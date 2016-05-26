<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GoCardlessPro\Client;
use \GoCardlessPro\Environment;
use JudoPay;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Payment;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\PaymentDeclinedException;

class JudopayService
{
    use CurrencyTrait;

    /** @var LoggerInterface */
    protected $logger;

    /** @var JudoPay */
    protected $client;

    /** @var string */
    protected $judoId;

    /** @var DocumentManager */
    protected $dm;

    /** @var PolicyService */
    protected $policyService;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param PolicyService   $policyService
     * @param string          $apiToken
     * @param string          $apiSecret
     * @param string          $judoId
     * @param boolean         $prod
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        PolicyService $policyService,
        $apiToken,
        $apiSecret,
        $judoId,
        $prod
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->policyService = $policyService;
        $this->judoId = $judoId;
        $data = array(
           'apiToken' => $apiToken,
           'apiSecret' => $apiSecret,
           'judoId' => $judoId,
        );
        if ($prod) {
            $data['endpointUrl'] = 'https://partnerapi.judopay.com/';
        } else {
            $data['endpointUrl'] = 'https://partnerapi.judopay-sandbox.com/';
        }
        $this->client = new Judopay($data);
    }

    /**
     * @param Policy $policy
     * @param string $receiptId
     * @param string $consumerToken
     * @param string $cardToken     Can be null if card is declined
     */
    public function add(Policy $policy, $receiptId, $consumerToken, $cardToken)
    {
        $user = $policy->getUser();

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken($consumerToken);
        if ($cardToken) {
            $judo->addCardToken($cardToken, null);
        }
        $user->setPaymentMethod($judo);

        $payment = $this->validateReceipt($policy, $receiptId, $cardToken);

        // TODO: create payment schedule
        $this->validateUser($policy->getUser());
        $this->policyService->create($policy, $policy->getUser());

        return true;
    }

    public function testPay(User $user, $ref, $amount, $cardNumber, $expiryDate, $cv2)
    {
        $payment = $this->client->getModel('CardPayment');
        $payment->setAttributeValues(
            array(
                'judoId' => $this->judoId,
                'yourConsumerReference' => $user->getId(),
                'yourPaymentReference' => $ref,
                'amount' => $amount,
                'currency' => 'GBP',
                'cardNumber' => $cardNumber,
                'expiryDate' => $expiryDate,
                'cv2' => $cv2,
            )
        );
        $details = $payment->create();

        return $details['receiptId'];
    }

    /**
     * @param Policy $policy
     * @param string $receiptId
     * @param string $cardToken Can be null if card is declined
     */
    public function validateReceipt(Policy $policy, $receiptId, $cardToken)
    {
        $transaction = $this->client->getModel('Transaction');
        $transactionDetails = $transaction->find($receiptId);

        $payment = new JudoPayment();
        $payment->setReference($transactionDetails["yourPaymentReference"]);
        $payment->setReceipt($transactionDetails["receiptId"]);
        $payment->setAmount($transactionDetails["amount"]);
        $payment->setResult($transactionDetails["result"]);
        $payment->setMessage($transactionDetails["message"]);
        $policy->addPayment($payment);

        $judoPaymentMethod = $policy->getUser()->getPaymentMethod();
        if ($cardToken) {
            $tokens = $judoPaymentMethod->getCardTokens();
            if (!isset($tokens[$cardToken]) || !$tokens[$cardToken]) {
                $judoPaymentMethod->addCardToken($cardToken, json_encode($transactionDetails['cardDetails']));
            }
        }

        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // Ensure the correct amount is paid
        // TODO: Should we issue a refund in this case??
        $premium = $policy->getPremium();
        if (!in_array($this->toTwoDp($payment->getAmount()), [
                $this->toTwoDp($premium->getMonthlyPremiumPrice()),
                $this->toTwoDp($premium->getYearlyPremiumPrice()),
            ])) {
            $errMsg = sprintf(
                'REFUNDED NEEDED!! Expected %f or %f, not %f for payment id: %s',
                $premium->getMonthlyPremiumPrice(),
                $premium->getYearlyPremiumPrice(),
                $payment->getAmount(),
                $payment->getId()
            );
            $this->logger->error($errMsg);

            throw new InvalidPremiumException($errMsg);
        }
        if ($payment->getResult() != JudoPayment::RESULT_SUCCESS) {
            // We've recorded the payment - can return error now
            throw new PaymentDeclinedException();
        }

        return $payment;
    }

    protected function validateUser($user)
    {
        if (!$user->hasValidDetails()) {
            throw new \InvalidArgumentException(sprintf(
                'User is missing details such as name or email address (User: %s)',
                $user->getId()
            ));
        }

        if (!$user->hasValidBillingDetails()) {
            throw new \InvalidArgumentException(sprintf(
                'User is missing details such as billing address (User: %s)',
                $user->getId()
            ));
        }
    }

    protected function tokenPay(Policy $policy, $consumerToken, $cardToken)
    {
        // TODO: Validate we haven't already paid
        $amount = $policy->getPremium()->getMonthlyPremiumPrice();
        $user = $policy->getUser();

        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // add payment
        $tokenPayment = $this->client->getModel('TokenPayment');

        // populate the required data fields.
        $tokenPayment->setAttributeValues(
            array(
                'judoId' => $this->judoId,
                'yourConsumerReference' => $user->getId(),
                'yourPaymentReference' => $payment->getId(),
                'amount' => $amount,
                'currency' => 'GBP',
                'consumerToken' => $consumerToken,
                'cardToken' => $cardToken,
                'emailAddress' => $user->getEmail(),
                'mobileNumber' => $user->getMobileNumber(),
            )
        );

        $tokenPaymentDetails = $tokenPayment->create();
        $payment->setReceipt($tokenPaymentDetails["receiptId"]);
        $payment->setAmount($tokenPaymentDetails["amount"]);
        if ($tokenPaymentDetails["type"] != 'Payment') {
            $errMsg = sprintf('Payment type mismatch - expected payment, not %s', $tokenPaymentDetails["type"]);
            $this->logger->error($errMsg);
            // save up to this point
            $this->dm->flush(null, array('w' => 'majority', 'j' => true));
        }
        if ($payment->getId() != $tokenPaymentDetails["yourPaymentReference"]) {
            $errMsg = sprintf(
                'Payment ref mismatch. %s != %s',
                $payment->getId(),
                $tokenPaymentDetails["yourPaymentReference"]
            );
            $this->logger->error($errMsg);
            // save up to this point
            $this->dm->flush(null, array('w' => 'majority', 'j' => true));

            throw new \Exception($errMsg);
        }

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));
    }

    /**
     *
     */
    public function webpay(User $user, Phone $phone, $amount, $ipAddress, $userAgent)
    {
        $payment = new Payment();
        $payment->setAmount($amount);
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // add payment
        $webPayment = $this->client->getModel('WebPayments\Payment');

        // populate the required data fields.
        $webPayment->setAttributeValues(
            array(
                'judoId' => $this->judoId,
                'yourConsumerReference' => $user->getId(),
                'yourPaymentReference' => $payment->getId(),
                'amount' => $amount,
                'currency' => 'GBP',
                'clientIpAddress' => $ipAddress,
                'clientUserAgent' => $userAgent,
            )
        );

        $webpaymentDetails = $webPayment->create();
        $payment->setReference($webpaymentDetails["reference"]);

        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setPhone($phone);
        $policy->addPayment($payment);
        $this->dm->persist($policy);

        $payment->setPolicy($policy);

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return array('post_url' => $webpaymentDetails["postUrl"], 'payment' => $payment);
    }
    
    /**
     * Record a successful payment
     *
     * @param string $reference
     * @param string $receipt
     * @param string $token
     *
     * @return Policy
     */
    public function paymentSuccess($reference, $receipt, $token)
    {
        $repo = $this->dm->getRepository(Payment::class);
        $payment = $repo->findOneBy(['reference' => $reference]);
        if (!$payment) {
            throw new \Exception('Unable to locate payment');
        }

        // TODO: Encrypt
        $payment->setToken($token);
        $payment->setReceipt($receipt);
        $payment->getPolicy()->create();

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // TODO: Email receipt?

        return $payment->getPolicy();
    }
}
