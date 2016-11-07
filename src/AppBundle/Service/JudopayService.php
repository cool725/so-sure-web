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
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\PaymentDeclinedException;
use AppBundle\Classes\Salva;

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

    /** @var MailerService */
    protected $mailer;

    protected $statsd;

    /** @var string */
    protected $environment;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param PolicyService   $policyService
     * @param MailerService   $mailer
     * @param string          $apiToken
     * @param string          $apiSecret
     * @param string          $judoId
     * @param string          $environment
     * @param                 $statsd
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        PolicyService $policyService,
        MailerService $mailer,
        $apiToken,
        $apiSecret,
        $judoId,
        $environment,
        $statsd
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->policyService = $policyService;
        $this->judoId = $judoId;
        $this->mailer = $mailer;
        $data = array(
           'apiToken' => $apiToken,
           'apiSecret' => $apiSecret,
           'judoId' => $judoId,
           'useProduction' => $environment == 'prod',
           // endpointUrl is overwriten in Judopay Configuration Constructor
           // 'endpointUrl' => ''
        );
        $this->client = new Judopay($data);
        $this->statsd = $statsd;
        $this->environment = $environment;
    }

    public function getTransactions($pageSize)
    {
        $repo = $this->dm->getRepository(JudoPayment::class);
        $transactions = $this->client->getModel('Transaction');
        $data = array(
            'judoId' => $this->judoId,
        );

        $transactions->setAttributeValues($data);
        $details = $transactions->all(0, $pageSize);
        $result = [
            'validated' => 0,
            'missing' => 0,
            'non-payment' => 0
        ];
        foreach ($details['results'] as $receipt) {
            if ($receipt['type'] == 'Payment') {
                $payment = $repo->findOneBy(['receipt' => $receipt['receiptId']]);
                if (!$payment) {
                    $this->logger->warning(sprintf(
                        'Missing judo payment item for receipt %s on %s [%s]',
                        $receipt['receiptId'],
                        $receipt['createdAt'],
                        json_encode($receipt)
                    ));
                    $result['missing']++;
                } else {
                    $result['validated']++;
                }
            } else {
                $result['non-payment']++;
            }
        }

        return $result;
    }

    /**
     * @param Policy    $policy
     * @param string    $receiptId
     * @param string    $consumerToken
     * @param string    $cardToken     Can be null if card is declined
     * @param string    $deviceDna     Optional device dna data (json encoded) for judoshield
     * @param \DateTime $date
     */
    public function add(
        Policy $policy,
        $receiptId,
        $consumerToken,
        $cardToken,
        $deviceDna = null,
        \DateTime $date = null
    ) {
        $this->statsd->startTiming("judopay.add");
        // doesn't make sense to add payments for cancelled or expired policies
        if (in_array($policy->getStatus(), [PhonePolicy::STATUS_CANCELLED, PhonePolicy::STATUS_EXPIRED])) {
            throw new \Exception('Unable to apply payment to cancelled/expired policy');
        }

        // TODO: Perhaps this should be allowed - just in case there were multiple payments?
        if ($policy->getStatus() == PhonePolicy::STATUS_ACTIVE) {
            return true;
        }

        if (!$policy->getStatus() || $policy->getStatus() == PhonePolicy::STATUS_PENDING) {
            // New policy

            // Mark policy as pending for monitoring purposes
            $policy->setStatus(PhonePolicy::STATUS_PENDING);
            $this->dm->flush();

            $this->createPayment($policy, $receiptId, $consumerToken, $cardToken, $deviceDna, $date);

            $this->policyService->create($policy, $date);
            $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
            $this->dm->flush();
        } else {
            // Existing policy - add payment + prevent duplicate billing
            $this->createPayment($policy, $receiptId, $consumerToken, $cardToken, $deviceDna, $date);
            $this->policyService->adjustScheduledPayments($policy, $date);

            $isPaidToDate = $policy->isPolicyPaidToDate(false, $policy->getPolicyPrefix($this->environment), $date);
            // update status if it makes sense to
            if ($isPaidToDate &&
                in_array($policy->getStatus(), [PhonePolicy::STATUS_UNPAID, PhonePolicy::STATUS_PENDING])
            ) {
                $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
            } elseif (!$isPaidToDate) {
                $this->logger->error(sprintf('Policy %s is not paid to date', $policy->getPolicyNumber()));
            }
            $this->dm->flush();
        }

        $this->statsd->endTiming("judopay.add");

        return true;
    }

    protected function createPayment(
        Policy $policy,
        $receiptId,
        $consumerToken,
        $cardToken,
        $deviceDna = null,
        \DateTime $date = null
    ) {
        $user = $policy->getUser();

        $judo = new JudoPaymentMethod();
        $judo->setCustomerToken($consumerToken);
        if ($cardToken) {
            $judo->addCardToken($cardToken, null);
        }
        if ($deviceDna) {
            $judo->setDeviceDna($deviceDna);
        }
        $user->setPaymentMethod($judo);

        $payment = $this->validateReceipt($policy, $receiptId, $cardToken, $date);

        $this->validateUser($user);
    }

    public function testPay(User $user, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId = null)
    {
        return $this->testPayDetails($user, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId)['receiptId'];
    }

    public function testPayDetails(User $user, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId = null)
    {
        $payment = $this->client->getModel('CardPayment');
        $data = array(
            'judoId' => $this->judoId,
            'yourConsumerReference' => $user->getId(),
            'yourPaymentReference' => $ref,
            'amount' => $amount,
            'currency' => 'GBP',
            'cardNumber' => $cardNumber,
            'expiryDate' => $expiryDate,
            'cv2' => $cv2,
        );

        if ($policyId) {
            $data['yourPaymentMetaData'] = ['policy_id' => $policyId];
        }

        $payment->setAttributeValues($data);
        $details = $payment->create();

        return $details;
    }

    public function getReceipt($receiptId)
    {
        $transaction = $this->client->getModel('Transaction');

        try {
            $transactionDetails = $transaction->find($receiptId);
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Error retrieving receipt %s. Ex: %s',
                $receiptId,
                $e
            ));

            throw $e;
        }

        return $transactionDetails;
    }

    /**
     * @param Policy $policy
     * @param string $receiptId
     * @param string $cardToken Can be null if card is declined
     */
    public function validateReceipt(Policy $policy, $receiptId, $cardToken, \DateTime $date = null)
    {
        $transactionDetails = $this->getReceipt($receiptId);
        $repo = $this->dm->getRepository(JudoPayment::class);
        $exists = $repo->findOneBy(['receipt' => $transactionDetails["receiptId"]]);
        if ($exists) {
            throw new \DomainException(sprintf(
                "Receipt %s has already been used to pay for a policy",
                $transactionDetails['receiptId']
            ));
        }

        $payment = new JudoPayment();
        $payment->setReference($transactionDetails["yourPaymentReference"]);
        $payment->setReceipt($transactionDetails["receiptId"]);
        $payment->setAmount($transactionDetails["amount"]);
        $payment->setResult($transactionDetails["result"]);
        $payment->setMessage($transactionDetails["message"]);
        if ($date) {
            $payment->setDate($date);
        }
        $policy->addPayment($payment);

        $judoPaymentMethod = $policy->getUser()->getPaymentMethod();
        if ($cardToken) {
            $tokens = $judoPaymentMethod->getCardTokens();
            if (!isset($tokens[$cardToken]) || !$tokens[$cardToken]) {
                $judoPaymentMethod->addCardToken($cardToken, json_encode($transactionDetails['cardDetails']));
                if (isset($transactionDetails['cardDetails']['cardLastfour'])) {
                    $payment->setCardLastFour($transactionDetails['cardDetails']['cardLastfour']);
                } elseif (isset($transactionDetails['cardDetails']['cardLastFour'])) {
                    $payment->setCardLastFour($transactionDetails['cardDetails']['cardLastFour']);
                }
            }
        }

        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        if (!isset($transactionDetails["yourPaymentMetaData"]) ||
            !isset($transactionDetails["yourPaymentMetaData"]["policy_id"])) {
            $this->logger->warning(sprintf('Unable to find policy id metadata for payment id %s', $payment->getId()));
        } elseif ($transactionDetails["yourPaymentMetaData"]["policy_id"] != $policy->getId()) {
            $this->logger->error(sprintf(
                'Payment id %s metadata [%s] does not match policy id %s',
                $payment->getId(),
                json_encode($transactionDetails["yourPaymentMetaData"]),
                $policy->getId()
            ));
        }

        // Ensure the correct amount is paid
        $this->validatePaymentAmount($payment);

        if ($payment->getResult() != JudoPayment::RESULT_SUCCESS) {
            // We've recorded the payment - can return error now
            throw new PaymentDeclinedException();
        }

        // Only set broker fees if we know the amount
        if ($this->areEqualToFourDp($payment->getAmount(), $policy->getPremium()->getYearlyPremiumPrice())) {
            // Yearly broker will include the final monthly calc in the total
            $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        } elseif ($this->areEqualToFourDp($payment->getAmount(), $policy->getPremium()->getMonthlyPremiumPrice())) {
            // This is always the first payment, so shouldn't have to worry about the final one
            $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        } else {
            $this->logger->error(sprintf(
                'Failed set correct commission for %f (policy %s)',
                $payment->getAmount(),
                $policy->getId()
            ));
        }

        return $payment;
    }

    protected function validatePaymentAmount(JudoPayment $payment)
    {
        // TODO: Should we issue a refund in this case??
        $premium = $payment->getPolicy()->getPremium();
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

        /* TODO: May want to validate this data??
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
        */
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

    public function scheduledPayment(ScheduledPayment $scheduledPayment, $prefix = null, $date = null)
    {
        if (!$scheduledPayment->isBillable($prefix)) {
            throw new \Exception(sprintf(
                'Scheduled payment %s is not billable (status: %s)',
                $scheduledPayment->getId(),
                $scheduledPayment->getStatus()
            ));
        }

        if (!$scheduledPayment->canBeRun($date)) {
            throw new \Exception(sprintf(
                'Scheduled payment %s can not yet be run (scheduled: %s)',
                $scheduledPayment->getId(),
                $scheduledPayment->getScheduled()->format('Y-m-d H:i:s')
            ));
        }

        if ($scheduledPayment->getPayment() &&
            $scheduledPayment->getPayment()->getResult() == JudoPayment::RESULT_SUCCESS) {
            throw new \Exception(sprintf(
                'Payment already received for scheduled payment %s',
                $scheduledPayment->getId()
            ));
        }

        $policy = $scheduledPayment->getPolicy();
        $paymentMethod = $policy->getUser()->getPaymentMethod();
        if (!$paymentMethod || !$paymentMethod instanceof JudoPaymentMethod) {
            throw new \Exception(sprintf(
                'Payment method not valid for scheduled payment %s',
                $scheduledPayment->getId()
            ));
        }
        try {
            $payment = $this->tokenPay($policy, $scheduledPayment->getType());
            $this->processScheduledPaymentResult($scheduledPayment, $payment);
            $this->dm->flush(null, array('w' => 'majority', 'j' => true));
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Error running scheduled payment %s. Ex: %s',
                $scheduledPayment->getId(),
                $e->getMessage()
            ));
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_FAILED);
            $this->dm->flush(null, array('w' => 'majority', 'j' => true));

            throw $e;
        }

        return $scheduledPayment;
    }

    public function processScheduledPaymentResult($scheduledPayment, $payment, \DateTime $date = null)
    {
        $policy = $scheduledPayment->getPolicy();
        $scheduledPayment->setPayment($payment);
        if ($payment->getResult() == JudoPayment::RESULT_SUCCESS) {
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SUCCESS);
        } else {
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_FAILED);
            // Very important to update status to unpaid as used by the app to update payment
            // and used by expire process to cancel policy if unpaid after 30 days
            $policy->setStatus(PhonePolicy::STATUS_UNPAID);
            $this->dm->flush(null, array('w' => 'majority', 'j' => true));

            $repo = $this->dm->getRepository(ScheduledPayment::class);

            // Only allow up to 4 failed payment attempts
            if ($repo->countUnpaidScheduledPayments($policy, $date) <= 4) {
                // create another scheduled payment for 7 days later
                $rescheduled = $scheduledPayment->reschedule($date);
                $policy->addScheduledPayment($rescheduled);

                $this->failedPaymentEmail($policy, $rescheduled->getScheduled());
            } else {
                // TODO: Should probably be a final warning email
                $this->failedPaymentEmail($policy, null);
            }
        }
    }

    /**
     * @param Policy    $policy
     * @param \DateTime $next
     */
    private function failedPaymentEmail(Policy $policy, $next)
    {
        $baseTemplate = sprintf('AppBundle:Email:policy/failedPayment');
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $this->mailer->sendTemplate(
            sprintf('Payment failure for your so-sure policy %s', $policy->getPolicyNumber()),
            $policy->getUser()->getEmail(),
            $htmlTemplate,
            ['policy' => $policy, 'next' => $next],
            $textTemplate,
            ['policy' => $policy, 'next' => $next]
        );
    }

    public function runTokenPayment(User $user, $amount, $paymentRef, $policyId, $customerRef = null)
    {
        $paymentMethod = $user->getPaymentMethod();
        if (!$customerRef) {
            $customerRef = $user->getId();
        }

        // add payment
        $tokenPayment = $this->client->getModel('TokenPayment');

        $data = array(
                'judoId' => $this->judoId,
                'yourConsumerReference' => $customerRef,
                'yourPaymentReference' => $paymentRef,
                'yourPaymentMetaData' => [
                    'policy_id' => $policyId,
                ],
                'amount' => $amount,
                'currency' => 'GBP',
                'consumerToken' => $paymentMethod->getCustomerToken(),
                'cardToken' => $paymentMethod->getCardToken(),
                'emailAddress' => $user->getEmail(),
                'mobileNumber' => $user->getMobileNumber(),
        );
        if ($paymentMethod->getDecodedDeviceDna() && is_array($paymentMethod->getDecodedDeviceDna())) {
            $data['clientDetails'] = $paymentMethod->getDecodedDeviceDna();
        } else {
            // We should always have the clientDetails
            $this->logger->warning(sprintf('Missing JudoPay DeviceDna for user %s', $user->getId()));
        }

        // populate the required data fields.
        $tokenPayment->setAttributeValues($data);

        try {
            $tokenPaymentDetails = $tokenPayment->create();
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error running token payment %s. Ex: %s', $paymentRef, $e));

            throw $e;
        }

        return $tokenPaymentDetails;
    }

    protected function tokenPay(Policy $policy, $notes = null)
    {
        $amount = $policy->getPremium()->getMonthlyPremiumPrice();
        $user = $policy->getUser();

        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $payment->setNotes($notes);
        $policy->addPayment($payment);
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        $tokenPaymentDetails = $this->runTokenPayment($user, $amount, $payment->getId(), $policy->getId());

        $payment->setReference($tokenPaymentDetails["yourPaymentReference"]);
        $payment->setReceipt($tokenPaymentDetails["receiptId"]);
        $payment->setAmount($tokenPaymentDetails["amount"]);
        $payment->setResult($tokenPaymentDetails["result"]);
        $payment->setMessage($tokenPaymentDetails["message"]);

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // Ensure the correct amount is paid
        $this->validatePaymentAmount($payment);

        $this->setCommission($policy, $payment);

        return $payment;
    }

    public function setCommission($policy, $payment)
    {
        // TODO: Should this only be for success payments?
        $salva = new Salva();
        $payment->setTotalCommission($salva->getTotalCommission($policy));
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

        $policy = new SalvaPhonePolicy();
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

    /**
     * Refund a payment
     *
     * @param JudoPayment $payment
     * @param float       $amount         Amount to refund (or null for entire initial amount)
     * @param float       $totalCommision Total commission amount to refund (or null for entire amount from payment)
     * @param string      $notes
     *
     * @return JudoPayment
     */
    public function refund(JudoPayment $payment, $amount = null, $totalCommision = null, $notes = null)
    {
        if (!$amount) {
            $amount = $payment->getAmount();
        }
        if (!$totalCommision) {
            $totalCommision = $payment->getTotalCommission();
        }

        // Refund is a negative payment
        $refund = new JudoPayment();
        $refund->setAmount(0 - $amount);
        $refund->setNotes($notes);
        $payment->getPolicy()->addPayment($refund);
        $this->dm->persist($refund);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // add refund
        $refundModel = $this->client->getModel('Refund');

        $data = array(
                'judoId' => $this->judoId,
                'receiptId' => $payment->getReceipt(),
                'yourPaymentReference' => $refund->getId(),
                'amount' => abs($refund->getAmount()),
        );

        // populate the required data fields.
        $refundModel->setAttributeValues($data);

        try {
            $refundModelDetails = $refundModel->create();
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error running refund %s', $refund->getId()), ['exception' => $e]);

            throw $e;
        }

        $refund->setReference($refundModelDetails["yourPaymentReference"]);
        // as refund receipt is the same, add prefix to prevent duplciates in db, so we can have unique index
        $refund->setReceipt(sprintf('R-%s', $refundModelDetails["receiptId"]));
        $refund->setAmount(0 - $refundModelDetails["amount"]);
        $refund->setResult($refundModelDetails["result"]);
        $refund->setMessage($refundModelDetails["message"]);

        $refund->setRefundTotalCommission($totalCommision);

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return $refund;
    }

    public function processCsv($judoFile)
    {
        $filename = $judoFile->getFile();
        $header = null;
        $lines = array();
        $payments = 0;
        $numPayments = 0;
        $refunds = 0;
        $numRefunds = 0;
        $total = 0;
        $maxDate = null;
        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000)) !== false) {
                if (!$header) {
                    $header = $row;
                } else {
                    $line = array_combine($header, $row);
                    $lines[] = $line;
                    if ($line['TransactionType'] == "Payment") {
                        $total += $line['Net'];
                        $payments += $line['Net'];
                        $numPayments++;
                    } elseif ($line['TransactionType'] == "Refund") {
                        $total -= $line['Net'];
                        $refunds += $line['Net'];
                        $numRefunds++;
                    }
                    $date = new \DateTime($line['Date']);
                    if ($maxDate && $maxDate->format('m') != $date->format('m')) {
                        throw new \Exception('Export should only be for the same calendar month');
                    }

                    if (!$maxDate || $maxDate > $date) {
                        $maxDate = $date;
                    }
                }
            }
            fclose($handle);
        }

        $data = [
            'total' => $this->toTwoDp($total),
            'payments' => $this->toTwoDp($payments),
            'numPayments' => $numPayments,
            'refunds' => $this->toTwoDp($refunds),
            'numRefunds' => $numRefunds,
            'date' => $maxDate,
            'data' => $lines,
        ];
    
        $judoFile->addMetadata('total', $data['total']);
        $judoFile->addMetadata('payments', $data['payments']);
        $judoFile->addMetadata('numPayments', $data['numPayments']);
        $judoFile->addMetadata('refunds', $data['refunds']);
        $judoFile->addMetadata('numRefunds', $data['numRefunds']);
        $judoFile->setDate($data['date']);

        return $data;
    }
}
