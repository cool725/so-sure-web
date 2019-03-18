<?php
namespace AppBundle\Service;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Charge;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\JudoFile;
use AppBundle\Document\IdentityLog;
use AppBundle\Repository\JudoPaymentRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

use Judopay;

use AppBundle\Classes\Salva;

use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\Feature;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\MultiPay;
use AppBundle\Document\CurrencyTrait;

use AppBundle\Event\PaymentEvent;
use AppBundle\Event\ScheduledPaymentEvent;
use AppBundle\Event\PolicyEvent;

use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\PaymentDeclinedException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\SameDayPaymentException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class JudopayService
{
    use CurrencyTrait;
    use DateTrait;

    const MAX_HOUR_DELAY_FOR_RECEIPTS = 2;

    /** Standard payment (monthly/yearly; initial payment */
    const WEB_TYPE_STANDARD = 'standard';

    /** No payment, just updating the card details */
    const WEB_TYPE_CARD_DETAILS = 'card-details';

    /** Remainder of policy payment (typically cancelled policy w/claim) */
    const WEB_TYPE_REMAINDER = 'remainder';

    /** Payment after card fails */
    const WEB_TYPE_UNPAID = 'unpaid';

    /** @var LoggerInterface */
    protected $logger;

    /** @var Judopay */
    protected $apiClient;

    /** @var Judopay */
    protected $webClient;

    /** @var string */
    protected $judoId;

    /** @var DocumentManager */
    protected $dm;

    /** @var PolicyService */
    protected $policyService;

    /** @var MailerService */
    protected $mailer;

    /** @var \Domnikl\Statsd\Client */
    protected $statsd;

    /** @var string */
    protected $environment;

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    /** @var SmsService */
    protected $sms;

    /** @var FeatureService */
    protected $featureService;

    public function setDispatcher($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param DocumentManager          $dm
     * @param LoggerInterface          $logger
     * @param PolicyService            $policyService
     * @param MailerService            $mailer
     * @param string                   $apiToken
     * @param string                   $apiSecret
     * @param string                   $judoId
     * @param string                   $environment
     * @param \Domnikl\Statsd\Client   $statsd
     * @param string                   $webToken
     * @param string                   $webSecret
     * @param EventDispatcherInterface $dispatcher
     * @param SmsService               $sms
     * @param FeatureService           $featureService
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
        \Domnikl\Statsd\Client $statsd,
        $webToken,
        $webSecret,
        EventDispatcherInterface $dispatcher,
        SmsService $sms,
        FeatureService $featureService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->policyService = $policyService;
        $this->judoId = $judoId;
        $this->mailer = $mailer;
        $this->dispatcher = $dispatcher;
        $this->sms = $sms;
        $apiData = array(
           'apiToken' => $apiToken,
           'apiSecret' => $apiSecret,
           'judoId' => $judoId,
           'useProduction' => $environment == 'prod',
           'apiVersion' => '5.6'
           // endpointUrl is overwriten in Judopay Configuration Constructor
           // 'endpointUrl' => ''
        );
        $this->apiClient = new Judopay($apiData);
        $webData = array(
           'apiToken' => $webToken,
           'apiSecret' => $webSecret,
           'judoId' => $judoId,
           'useProduction' => $environment == 'prod',
           // endpointUrl is overwriten in Judopay Configuration Constructor
           // 'endpointUrl' => ''
        );
        $this->webClient = new Judopay($webData);
        $this->statsd = $statsd;
        $this->environment = $environment;
        $this->featureService = $featureService;
    }

    public function getTransaction($receiptId)
    {
        /** @var Judopay\Model $transaction */
        $transaction = $this->apiClient->getModel('Transaction');
        $data = array(
            'judoId' => $this->judoId,
        );
        $transaction->setAttributeValues($data);
        $details = $transaction->find($receiptId);

        return $details;
    }

    public function getTransactionWebType($receiptId)
    {
        try {
            $data = $this->getTransaction($receiptId);
            if (isset($data['yourPaymentMetaData']) && isset($data['yourPaymentMetaData']['web_type'])) {
                return $data['yourPaymentMetaData']['web_type'];
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                sprintf('Unable to find transaction receipt %s', $receiptId),
                ['exception' => $e]
            );
        }

        return null;
    }

    public function getTransactions($pageSize, $logMissing = true)
    {
        $policies = [];
        $repo = $this->dm->getRepository(JudoPayment::class);
        /** @var Judopay\Model $transactions */
        $transactions = $this->apiClient->getModel('Transaction');
        $data = array(
            'judoId' => $this->judoId,
        );

        $transactions->setAttributeValues($data);
        $details = $transactions->all(0, $pageSize);
        $data = [
            'validated' => 0,
            'missing' => [],
            'invalid' => [],
            'non-payment' => 0,
            'skipped-too-soon' => 0,
            'additional-payments' => []
        ];
        foreach ($details['results'] as $receipt) {
            $policyId = null;
            $result = isset($reciept['result']) ? $receipt['result'] : null;
            if (isset($receipt['yourPaymentMetaData']) && isset($receipt['yourPaymentMetaData']['policy_id'])) {
                // Non-token payments (eg. user) may be tried several times in a row
                // Ideally would seperate out the user/token payments, but for now
                // use success as a proxy for that
                if ($result == JudoPayment::RESULT_SUCCESS) {
                    $policyId = $receipt['yourPaymentMetaData']['policy_id'];
                    if (!isset($policies[$policyId])) {
                        $policies[$policyId] = true;
                    } else {
                        if (!isset($data['additional-payments'][$policyId])) {
                            $data['additional-payments'][$policyId] = 0;
                        }
                        //$data['additional-payments'][$policyId]++;
                        $data['additional-payments'][$policyId] = json_encode($receipt);
                    }
                }
            }

            $receiptId = $receipt['receiptId'];
            /** @var JudoPayment $payment */
            $payment = $repo->findOneBy(['receipt' => $receiptId]);

            $created = new \DateTime($receipt['createdAt']);
            $now = \DateTime::createFromFormat('U', time());
            $diff = $now->getTimestamp() - $created->getTimestamp();
            // allow a few (5) minutes before warning if missing receipt
            if ($diff < 300) {
                $data['skipped-too-soon']++;
            } elseif (in_array($receipt['type'], [JudoPayment::TYPE_PAYMENT, JudoPayment::TYPE_REFUND]) &&
                $receipt['result'] == JudoPayment::RESULT_SUCCESS) {
                if (!$payment) {
                    if ($logMissing) {
                        $this->logger->error(sprintf(
                            'INVESTIGATE!! Missing db judo payment for received payment. receipt %s on %s [%s]',
                            $receiptId,
                            $receipt['createdAt'],
                            json_encode($receipt)
                        ));
                    }
                    $data['missing'][$receiptId] = isset($receipt['yourPaymentReference']) ?
                        $receipt['yourPaymentReference'] :
                        $receiptId;
                } elseif (!$payment->isSuccess()) {
                    if ($logMissing) {
                        $this->logger->error(sprintf(
                            'INVESTIGATE!! Judo payment status in db does not match judo. receipt %s on %s [%s]',
                            $receiptId,
                            $receipt['createdAt'],
                            json_encode($receipt)
                        ));
                    }
                    $data['invalid'][$receiptId] = isset($receipt['yourPaymentReference']) ?
                        $receipt['yourPaymentReference'] :
                        $receiptId;
                } else {
                    $data['validated']++;
                }
            } elseif (in_array($receipt['type'], [JudoPayment::TYPE_PAYMENT, JudoPayment::TYPE_REFUND]) &&
                $receipt['result'] != JudoPayment::RESULT_SUCCESS) {
                // can ignore failed missing payments
                // however if our db thinks it successful and judo says its not, that's problematic
                if ($payment && $payment->isSuccess()) {
                    if ($logMissing) {
                        $this->logger->error(sprintf(
                            'INVESTIGATE!! Judo payment status in db does not match judo. receipt %s on %s [%s]',
                            $receiptId,
                            $receipt['createdAt'],
                            json_encode($receipt)
                        ));
                    }
                    $data['invalid'][$receiptId] = isset($receipt['yourPaymentReference']) ?
                        $receipt['yourPaymentReference'] :
                        $receiptId;
                }
            } else {
                $data['non-payment']++;
            }
        }

        return $data;
    }

    /**
     * @param Policy      $policy
     * @param string      $receiptId
     * @param string      $consumerToken
     * @param string      $cardToken     Can be null if card is declined
     * @param string      $source        Source of the payment
     * @param string      $deviceDna     Optional device dna data (json encoded) for judoshield
     * @param \DateTime   $date
     * @param IdentityLog $identityLog
     */
    public function add(
        Policy $policy,
        $receiptId,
        $consumerToken,
        $cardToken,
        $source,
        $deviceDna = null,
        \DateTime $date = null,
        IdentityLog $identityLog = null
    ) {
        $this->statsd->startTiming("judopay.add");
        // doesn't make sense to add payments for expired policies
        if (in_array($policy->getStatus(), [
            PhonePolicy::STATUS_EXPIRED,
            PhonePolicy::STATUS_EXPIRED_CLAIMABLE,
            PhonePolicy::STATUS_EXPIRED_WAIT_CLAIM,
        ])) {
            throw new \Exception('Unable to apply payment to cancelled/expired policy');
        } elseif ($policy->getStatus() == PhonePolicy::STATUS_CANCELLED) {
            // a bit unusual, but for remainder payments w/claim it could occur
            $this->logger->warning(sprintf(
                'Payment is being applied to a cancelled policy %s',
                $policy->getId()
            ));
        } elseif ($policy->getStatus() == PhonePolicy::STATUS_ACTIVE) {
            // shouldn't really happen as policy should be in unpaid status
            // but seems to occur on occasion - make sure we credit that policy anyway
            $this->logger->info(sprintf(
                'Non-token payment is being applied to active policy %s',
                $policy->getId()
            ));
        }

        if (!$policy->getStatus() ||
            in_array($policy->getStatus(), [PhonePolicy::STATUS_PENDING, PhonePolicy::STATUS_MULTIPAY_REJECTED])) {
            // New policy

            // Mark policy as pending for monitoring purposes
            $policy->setStatus(PhonePolicy::STATUS_PENDING);
            $this->dm->flush();

            $payment = $this->createPayment(
                $policy,
                $receiptId,
                $consumerToken,
                $cardToken,
                $source,
                $deviceDna,
                $date
            );

            $this->policyService->create($policy, $date, true, null, $identityLog);
            $this->dm->flush();
        } else {
            // Existing policy - add payment + prevent duplicate billing
            $payment = $this->createPayment(
                $policy,
                $receiptId,
                $consumerToken,
                $cardToken,
                $source,
                $deviceDna,
                $date
            );
            if (!$this->policyService->adjustScheduledPayments($policy, true)) {
                // Reload object from db
                /** @var Policy $policy */
                $policy = $this->dm->merge($policy);
            }

            $this->validatePolicyStatus($policy, $date);
            $this->dm->flush();
        }

        // if a multipay user runs a payment direct on the policy, assume they want to remove multipay
        if ($policy->isDifferentPayer()) {
            $policy->setPayer($policy->getUser());
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
        $source,
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
        $policy->setPaymentMethod($judo);

        $payment = $this->validateReceipt($policy, $receiptId, $cardToken, $source, $date);

        $this->triggerPaymentEvent($payment);

        $this->validateUser($user);

        return $payment;
    }

    private function triggerPaymentEvent($payment)
    {
        if (!$payment) {
            return;
        }

        // Primarily used to allow tests to avoid triggering policy events
        if ($this->dispatcher) {
            if ($payment->isSuccess()) {
                $this->logger->debug('Event Payment Success');
                $this->dispatcher->dispatch(PaymentEvent::EVENT_SUCCESS, new PaymentEvent($payment));
            } else {
                $this->logger->debug('Event Payment Failed');
                $this->dispatcher->dispatch(PaymentEvent::EVENT_FAILED, new PaymentEvent($payment));
            }
        } else {
            $this->logger->warning('Dispatcher is disabled for Judo Service');
        }
    }

    private function triggerPolicyEvent($policy, $event, \DateTime $date = null)
    {
        if (!$policy) {
            return;
        }

        // Primarily used to allow tests to avoid triggering policy events
        if ($this->dispatcher) {
            $this->logger->debug(sprintf('Event %s', $event));
            $this->dispatcher->dispatch($event, new PolicyEvent($policy, $date));
        } else {
            $this->logger->warning('Dispatcher is disabled for Judo Service');
        }
    }

    public function testPay(User $user, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId = null)
    {
        return $this->testPayDetails($user, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId)['receiptId'];
    }

    public function testPayDetails(User $user, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId = null)
    {
        $data = array(
            'judoId' => $this->judoId,
            'yourConsumerReference' => $user->getId(),
            'yourPaymentReference' => $ref,
            'amount' => $this->toTwoDp($amount),
            'currency' => 'GBP',
            'cardNumber' => $cardNumber,
            'expiryDate' => $expiryDate,
            'cv2' => $cv2,
        );

        if ($policyId) {
            $data['yourPaymentMetaData'] = ['policy_id' => $policyId];
        }

        // simple way of cloning an array
        $dataCopy = json_decode(json_encode($data), true);
        if (!$data) {
            throw new \Exception('Missing data array');
        }

        try {
            /** @var Judopay\Model $payment */
            $payment = $this->apiClient->getModel('CardPayment');
            $payment->setAttributeValues($data);
            $details = $payment->create();
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending test payment: %s', json_encode($dataCopy)),
                ['exception' => $e]
            );

            // retry
            /** @var Judopay\Model $payment */
            $payment = $this->apiClient->getModel('CardPayment');
            $payment->setAttributeValues($dataCopy);
            $details = $payment->create();
        }

        return $details;
    }

    public function testRegisterDetails(User $user, $ref, $cardNumber, $expiryDate, $cv2)
    {
        /** @var Judopay\Model $register */
        $register = $this->apiClient->getModel('RegisterCard');
        $data = array(
            'judoId' => $this->judoId,
            'yourConsumerReference' => $user->getId(),
            'yourPaymentReference' => $ref,
            'amount' => 1.01,
            'currency' => 'GBP',
            'cardNumber' => $cardNumber,
            'expiryDate' => $expiryDate,
            'cv2' => $cv2,
        );

        $register->setAttributeValues($data);
        $details = $register->create();

        return $details;
    }

    public function getReceipt($receiptId, $enforceFullAmount = true, $enforceDate = true, \DateTime $date = null)
    {
        /** @var Judopay\Model $transaction */
        $transaction = $this->apiClient->getModel('Transaction');

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

        if ($transactionDetails['amount'] != $transactionDetails['netAmount']) {
            $msg = sprintf(
                'Judo receipt %s has a refund applied (net %s of %s).',
                $receiptId,
                $transactionDetails['netAmount'],
                $transactionDetails['amount']
            );
            if ($enforceFullAmount) {
                $this->logger->error($msg);

                throw new \Exception($msg);
            } else {
                $this->logger->warning($msg);
            }
        }

        // "2018-02-22T22:46:10.9625+00:00"
        $created = \DateTime::createFromFormat("Y-m-d\TH:i:s.uP", $transactionDetails['createdAt']);
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $diff = $date->diff($created);
        if ($diff->days > 0 || $diff->h >= self::MAX_HOUR_DELAY_FOR_RECEIPTS) {
            $msg = sprintf(
                'Judo receipt %s is older than expected (%d:%d hours).',
                $receiptId,
                $diff->days,
                $diff->h
            );
            if ($enforceDate) {
                $this->logger->error($msg);

                throw new \Exception($msg);
            } else {
                $this->logger->warning($msg);
            }
        }

        return $transactionDetails;
    }

    /**
     * @param User   $user
     * @param string $receiptId
     * @param string $consumerToken
     * @param string $cardToken     Can be null if card is declined
     * @param string $deviceDna     Optional device dna data (json encoded) for judoshield
     * @parma Policy $policy
     */
    public function updatePaymentMethod(
        User $user,
        $receiptId,
        $consumerToken,
        $cardToken,
        $deviceDna = null,
        Policy $policy = null
    ) {
        $transactionDetails = $this->getReceipt($receiptId);
        if ($transactionDetails["result"] != JudoPayment::RESULT_SUCCESS) {
            throw new PaymentDeclinedException();
        }
        /** @var JudoPaymentRepository $repo */
        $repo = $this->dm->getRepository(JudoPayment::class);
        /** @var JudoPayment $payment */
        $payment = $repo->findOneBy(['receipt' => $receiptId]);
        if ($payment) {
            $payment->setResult($transactionDetails["result"]);
            $payment->setMessage($transactionDetails["message"]);
        }

        // TODO: This should update on all policies
        $judo = null;
        if ($policy && $policy->getJudoPaymentMethod()) {
            $judo = $policy->getJudoPaymentMethod();
        }
        if (!$judo || !$judo instanceof JudoPaymentMethod) {
            $judo = new JudoPaymentMethod();
            if ($policy) {
                $policy->setPaymentMethod($judo);
            } else {
                foreach ($user->getValidPolicies(true) as $userPolicy) {
                    $userPolicy->setPaymentMethod($judo);
                }
            }
        }
        $judo->setCustomerToken($consumerToken);
        if ($cardToken) {
            $judo->addCardToken($cardToken, json_encode($transactionDetails['cardDetails']));
        }
        if ($deviceDna) {
            $judo->setDeviceDna($deviceDna);
        }

        // if a multipay user runs a payment direct on the policy, assume they want to remove multipay
        if ($policy && $policy->isDifferentPayer()) {
            // don't use $user as not validated that policy belongs to user
            $policy->setPayer($policy->getUser());
            $this->dm->flush();
        }
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));
    }

    /**
     * @param Policy    $policy
     * @param string    $receiptId
     * @param string    $cardToken Can be null if card is declined
     * @param string    $source
     * @param \DateTime $date
     */
    public function validateReceipt(Policy $policy, $receiptId, $cardToken, $source, \DateTime $date = null)
    {
        $transactionDetails = $this->getReceipt($receiptId);
        $repo = $this->dm->getRepository(JudoPayment::class);
        $exists = $repo->findOneBy(['receipt' => $transactionDetails["receiptId"]]);
        if ($exists) {
            throw new ProcessedException(sprintf(
                "Receipt %s has already been used to pay for a policy",
                $transactionDetails['receiptId']
            ));
        }

        // webpayment will already have a payment record

        // Try to find payment via policy object, so that there isn't any inconsistencies
        // Uncertain if this is doing anything productive or not, but there was an error
        // that seems like it could only be causes by loading an unflush db record - ch4972
        /** @var JudoPayment $payment */
        $payment = null;
        foreach ($policy->getPayments() as $payment) {
            if ($payment->getId() == $transactionDetails["yourPaymentReference"]) {
                break;
            }

            /** @var JudoPayment $payment */
            $payment = null;
        }
        // Fallback to db query if unable to find
        if (!$payment) {
            /** @var JudoPayment $payment */
            $payment = $repo->find($transactionDetails["yourPaymentReference"]);
        }

        if (!$payment) {
            $payment = new JudoPayment();
            $payment->setReference($transactionDetails["yourPaymentReference"]);
            $payment->setAmount($transactionDetails["amount"]);
            $this->dm->persist($payment);
            //\Doctrine\Common\Util\Debug::dump($payment);
            $policy->addPayment($payment);
        } else {
            if (!$this->areEqualToTwoDp($payment->getAmount(), $transactionDetails["amount"])) {
                $this->logger->error(sprintf(
                    'Payment %s Expected Matching Payment Amount %f',
                    $payment->getId(),
                    $transactionDetails["amount"]
                ));
            }
        }

        $payment->setReceipt($transactionDetails["receiptId"]);
        $payment->setResult($transactionDetails["result"]);
        $payment->setMessage($transactionDetails["message"]);
        if (isset($transactionDetails["riskScore"])) {
            $payment->setRiskScore($transactionDetails["riskScore"]);
        }
        // If wallet field is present, use that
        if (isset($transactionDetails["walletType"]) && $transactionDetails["walletType"] == 1) {
            $payment->setSource(Payment::SOURCE_APPLE_PAY);
        } elseif (isset($transactionDetails["walletType"]) && $transactionDetails["walletType"] == 2) {
            $payment->setSource(Payment::SOURCE_ANDROID_PAY);
        } else {
            $payment->setSource($source);
        }

        if ($date) {
            $payment->setDate($date);
        }

        /** @var JudoPaymentMethod $judoPaymentMethod */
        $judoPaymentMethod = $policy->getPolicyOrUserPaymentMethod();
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

        if ($judoPaymentMethod && !$payment->getDetails()) {
            $payment->setDetails($judoPaymentMethod->__toString());
        }

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

        $this->setCommission($payment);

        return $payment;
    }

    protected function validatePaymentAmount(JudoPayment $payment)
    {
        // TODO: Should we issue a refund in this case??
        $premium = $payment->getPolicy()->getPremium();
        if (!$premium->isEvenlyDivisible($payment->getAmount()) &&
            !$premium->isEvenlyDivisible($payment->getAmount(), true) &&
            !$this->areEqualToTwoDp($payment->getAmount(), $payment->getPolicy()->getOutstandingPremium())) {
            $errMsg = sprintf(
                'ADJUSTMENT NEEDED!! Expected %f or %f (or maybe %f), not %f for payment id: %s',
                $premium->getMonthlyPremiumPrice(),
                $premium->getYearlyPremiumPrice(),
                $payment->getPolicy()->getOutstandingPremium(),
                $payment->getAmount(),
                $payment->getId()
            );
            $this->logger->error($errMsg);
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

    /**
     * Run via scheduledPaymentService
     */
    public function scheduledPayment(
        ScheduledPayment $scheduledPayment,
        $prefix = null,
        \DateTime $date = null,
        $abortOnMultipleSameDayPayment = true
    ) {
        $scheduledPayment->validateRunable($prefix, $date);

        $payment = null;
        $policy = $scheduledPayment->getPolicy();
        $paymentMethod = $policy->getPolicyOrPayerOrUserJudoPaymentMethod();
        try {
            if (!$paymentMethod || !$paymentMethod instanceof JudoPaymentMethod) {
                throw new \Exception(sprintf(
                    'Payment method not valid for scheduled payment %s',
                    $scheduledPayment->getId()
                ));
            }

            $payment = $this->tokenPay(
                $policy,
                $scheduledPayment->getAmount(),
                $scheduledPayment->getNotes() ?: $scheduledPayment->getType(),
                $abortOnMultipleSameDayPayment,
                $date
            );
        } catch (SameDayPaymentException $e) {
            $this->dm->flush(null, array('w' => 'majority', 'j' => true));

            throw $e;
        } catch (\Exception $e) {
            // TODO: Nicer handling if Judo has an issue
            $this->logger->error(sprintf(
                'Error running scheduled payment %s. Ex: %s',
                $scheduledPayment->getId(),
                $e->getMessage()
            ));
        }

        if (!$payment) {
            $payment = new JudoPayment();
            $payment->setAmount(0);
            $payment->setResult(JudoPayment::RESULT_SKIPPED);
            if ($policy->getPolicyOrPayerOrUserJudoPaymentMethod()) {
                $payment->setDetails($policy->getPolicyOrPayerOrUserJudoPaymentMethod()->__toString());
            }
            $policy->addPayment($payment);
        }
        $this->processScheduledPaymentResult($scheduledPayment, $payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return $scheduledPayment;
    }

    public function validatePolicyStatus(Policy $policy, \DateTime $date = null)
    {
        // if payment fails at exactly same second as payment is due, technically policy is still paid to date
        // this is problematic if setting the policy state to unpaid prior to calling this method
        $isPaidToDate = $policy->isPolicyPaidToDate($date);
        // print sprintf('%s paid: %s', $policy->getStatus(), $isPaidToDate ? 'yes' : 'no') . PHP_EOL;
        // update status if it makes sense to
        if ($isPaidToDate &&
            in_array($policy->getStatus(), [PhonePolicy::STATUS_UNPAID, PhonePolicy::STATUS_PENDING])
        ) {
            $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
            $this->dm->flush(null, array('w' => 'majority', 'j' => true));
            $this->triggerPolicyEvent($policy, PolicyEvent::EVENT_REACTIVATED);
            // print 'status -> active' . PHP_EOL;
            // \Doctrine\Common\Util\Debug::dump($policy);
        } elseif (!$isPaidToDate) {
            $this->logger->error(sprintf('Policy %s is not paid to date', $policy->getPolicyNumber()));

            if (in_array($policy->getStatus(), [PhonePolicy::STATUS_ACTIVE, PhonePolicy::STATUS_PENDING])) {
                $policy->setStatus(PhonePolicy::STATUS_UNPAID);
                $this->dm->flush(null, array('w' => 'majority', 'j' => true));
                $this->triggerPolicyEvent($policy, PolicyEvent::EVENT_UNPAID);
            }
        }
    }

    public function getMailer()
    {
        return $this->mailer;
    }

    public function processScheduledPaymentResult(ScheduledPayment $scheduledPayment, $payment, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $policy = $scheduledPayment->getPolicy();
        if ($payment) {
            $scheduledPayment->setPayment($payment);
        }
        if ($payment && $payment->getResult() == JudoPayment::RESULT_SUCCESS) {
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SUCCESS);

            // will only be sent if card is expiring
            $this->cardExpiringEmail($policy, $date);

            $this->validatePolicyStatus($policy, $date);
        } else {
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_FAILED);
            // Very important to update status to unpaid as used by the app to update payment
            // and used by expire process to cancel policy if unpaid after 30 days
            $policy->setStatus(PhonePolicy::STATUS_UNPAID);
            $this->dm->flush(null, array('w' => 'majority', 'j' => true));
            $this->triggerPolicyEvent($policy, PolicyEvent::EVENT_UNPAID, $date);
            $this->dispatcher->dispatch(
                ScheduledPaymentEvent::EVENT_FAILED,
                new ScheduledPaymentEvent($scheduledPayment, $date)
            );
        }
    }

    /**
     * Should only be called if payment is successful (e.g. card is not already expired)
     *
     * @param Policy    $policy
     * @param \DateTime $date
     *
     * @return boolean true if email sent, false if card is not expiring next month
     */
    public function cardExpiringEmail(Policy $policy, \DateTime $date = null)
    {
        if (!$date) {
            $nextMonth = \DateTime::createFromFormat('U', time());
        } else {
            $nextMonth = clone $date;
        }
        $nextMonth->add(new \DateInterval('P1M'));

        if (!$policy->hasPolicyOrPayerOrUserJudoPaymentMethod() ||
            !$policy->getPolicyOrPayerOrUserJudoPaymentMethod()->isCardExpired($nextMonth)) {
            return false;
        }

        $baseTemplate = sprintf('AppBundle:Email:card/cardExpiring');
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $this->mailer->sendTemplateToUser(
            sprintf('Your card is expiring next month'),
            $policy->getPayerOrUser(),
            $htmlTemplate,
            ['policy' => $policy],
            $textTemplate,
            ['policy' => $policy],
            null,
            $this->featureService->isEnabled(Feature::FEATURE_PAYMENTS_BCC) ? 'bcc@so-sure.com' : null
        );

        return true;
    }

    /**
     * @param Policy    $policy
     * @param int       $failedPayments
     * @param \DateTime $next
     *
     * @return bool|string false if $failedPayments does not fall in range of 1-4. $baseTemplate if successfully sent
     */
    public function failedPaymentEmail(Policy $policy, $failedPayments, \DateTime $next = null)
    {
        // email only supported for 1, 2, 3, & 4
        if ($failedPayments < 1 || $failedPayments > 4) {
            return false;
        }

        $subject = sprintf('Payment failure for your so-sure policy %s', $policy->getPolicyNumber());
        if ($policy->hasMonetaryClaimed(true, true)) {
            $baseTemplate = sprintf('AppBundle:Email:card/failedPaymentWithClaim');
        } elseif (!$policy->hasPolicyOrUserValidPaymentMethod()) {
            $baseTemplate = sprintf('AppBundle:Email:card/cardMissing');
        } else {
            $baseTemplate = sprintf('AppBundle:Email:card/failedPayment');
        }

        $htmlTemplate = sprintf("%s-%d.html.twig", $baseTemplate, $failedPayments);
        $textTemplate = sprintf("%s-%d.txt.twig", $baseTemplate, $failedPayments);

        $this->mailer->sendTemplateToUser(
            $subject,
            $policy->getUser(),
            $htmlTemplate,
            ['policy' => $policy, 'next' => $next],
            $textTemplate,
            ['policy' => $policy, 'next' => $next],
            null,
            $this->featureService->isEnabled(Feature::FEATURE_PAYMENTS_BCC) ? 'bcc@so-sure.com' : null
        );

        return $baseTemplate;
    }

    /**
     * @param Policy    $policy
     * @param int       $failedPayments
     * @param \DateTime $next
     */
    private function failedPaymentSms(Policy $policy, $failedPayments, \DateTime $next = null)
    {
        if ($this->environment != 'prod') {
            return;
        }

        // sms only supported for 2, 3, & 4
        if ($failedPayments < 2 || $failedPayments > 4) {
            return;
        }

        $smsTemplate = sprintf('AppBundle:Sms:card/failedPayment-%d.txt.twig', $failedPayments);
        $this->sms->sendUser($policy, $smsTemplate, ['policy' => $policy, 'next' => $next], Charge::TYPE_SMS_PAYMENT);
    }

    public function runTokenPayment(Policy $policy, $amount, $paymentRef, $policyId, $customerRef = null)
    {
        /** @var JudoPaymentMethod $paymentMethod */
        $paymentMethod = $policy->getPolicyOrPayerOrUserJudoPaymentMethod();
        if (!$paymentMethod) {
            throw new \Exception(sprintf(
                'Unknown payment method for policy %s user %s',
                $policy->getId(),
                $policy->getPayerOrUser()->getId()
            ));
        }
        if (!$customerRef) {
            $customerRef = $policy->getPayerOrUser()->getId();
        }

        // add payment
        /** @var Judopay\Model $tokenPayment */
        $tokenPayment = $this->apiClient->getModel('TokenPayment');

        $data = array(
                'judoId' => $this->judoId,
                'yourConsumerReference' => $customerRef,
                'yourPaymentReference' => $paymentRef,
                'yourPaymentMetaData' => [
                    'policy_id' => $policyId,
                ],
                'amount' => $this->toTwoDp($amount),
                'currency' => 'GBP',
                'cardToken' => $paymentMethod->getCardToken(),
                'emailAddress' => $policy->getUser()->getEmail(),
                'mobileNumber' => $policy->getUser()->getMobileNumber(),
        );
        if ($this->featureService->isEnabled(Feature::FEATURE_JUDO_RECURRING)) {
            $data['recurringPayment'] = true;
        }
        // For webpayments, we won't have the customer token, but its optoinal anyway
        if ($paymentMethod->getCustomerToken()) {
            $data['consumerToken'] = $paymentMethod->getCustomerToken();
        }
        if ($paymentMethod->getDecodedDeviceDna() && is_array($paymentMethod->getDecodedDeviceDna())) {
            $data['clientDetails'] = $paymentMethod->getDecodedDeviceDna();
        } elseif ($paymentMethod->getDeviceDna() &&
            $paymentMethod->getDeviceDna() == JudoPaymentMethod::DEVICE_DNA_NOT_PRESENT) {
            // web payment, so no device dna
            \AppBundle\Classes\NoOp::ignore([]);
        } else {
            // May not have for older customers
            $this->logger->info(sprintf('Missing JudoPay DeviceDna for policy %s', $policy->getId()));
        }

        // populate the required data fields.
        $tokenPayment->setAttributeValues($data);

        try {
            $tokenPaymentDetails = $tokenPayment->create();
        } catch (\Judopay\Exception\ApiException $e) {
            $this->logger->warning(sprintf('Error running token payment (retrying) %s. Ex: %s', $paymentRef, $e));
            sleep(1);
            try {
                $tokenPaymentDetails = $tokenPayment->create();
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Error running retried token payment %s. Ex: %s', $paymentRef, $e));

                throw $e;
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error running token payment %s. Ex: %s', $paymentRef, $e));

            throw $e;
        }

        return $tokenPaymentDetails;
    }

    protected function tokenPay(
        Policy $policy,
        $amount = null,
        $notes = null,
        $abortOnMultipleSameDayPayment = true,
        \DateTime $date = null
    ) {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        foreach ($policy->getAllPayments() as $payment) {
            $diff = $date->diff($payment->getDate());
            if ($payment instanceof JudoPayment && $payment->getAmount() > 0 &&
                $diff->days == 0 && $payment->getSource() == Payment::SOURCE_TOKEN) {
                $msg = sprintf(
                    'Attempting to run addition payment for policy %s on the same day. %s',
                    $policy->getId(),
                    $abortOnMultipleSameDayPayment ? 'Aborting' : 'Please verify.'
                );
                if ($abortOnMultipleSameDayPayment) {
                    throw new SameDayPaymentException($msg);
                } else {
                    // to avoid constantly warning, only warn if minutes are < 15 - we run every 10 minutes
                    // so this should grab one trigger per hour (better than 6/hour)
                    if ($diff->i < 15) {
                        $this->logger->warning($msg);
                    } else {
                        $this->logger->info($msg);
                    }
                }
            }
        }

        if (!$amount) {
            $amount = $policy->getPremium()->getMonthlyPremiumPrice();
        }
        $user = $policy->getPayerOrUser();

        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $payment->setNotes($notes);
        $payment->setUser($policy->getUser());
        $payment->setSource(Payment::SOURCE_TOKEN);
        if ($policy->getPolicyOrPayerOrUserJudoPaymentMethod()) {
            $payment->setDetails($policy->getPolicyOrPayerOrUserJudoPaymentMethod()->__toString());
        }
        $policy->addPayment($payment);
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        if ($policy->hasPolicyOrUserValidPaymentMethod()) {
            $tokenPaymentDetails = $this->runTokenPayment($policy, $amount, $payment->getId(), $policy->getId());

            $payment->setReference($tokenPaymentDetails["yourPaymentReference"]);
            $payment->setReceipt($tokenPaymentDetails["receiptId"]);
            $payment->setAmount($tokenPaymentDetails["amount"]);
            $payment->setResult($tokenPaymentDetails["result"]);
            $payment->setMessage($tokenPaymentDetails["message"]);
            if (isset($tokenPaymentDetails["riskScore"])) {
                $payment->setRiskScore($tokenPaymentDetails["riskScore"]);
            }
        } else {
            $this->logger->info(sprintf(
                'User %s does not have a valid payment method (Policy %s)',
                $user->getId(),
                $policy->getId()
            ));
            $payment->setResult(JudoPayment::RESULT_SKIPPED);
        }

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // Ensure the correct amount is paid
        $this->validatePaymentAmount($payment);

        // TODO: Validate receipt does not set commission on failed payments, but token does
        // make consistent
        $this->setCommission($payment);

        $this->triggerPaymentEvent($payment);

        return $payment;
    }

    public function setCommission($payment)
    {
        try {
            $payment->setCommission();
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     *
     */
    public function webpay(Policy $policy, $amount, $ipAddress, $userAgent, $type = null)
    {
        if ($this->areEqualToTwoDp(0, $amount)) {
            throw new \Exception(sprintf('Amount must be > 0 for policy %s', $policy->getId()));
        }

        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $payment->setUser($policy->getUser());
        $payment->setSource(Payment::SOURCE_WEB);
        $payment->setWebType($type);

        if ($type == self::WEB_TYPE_REMAINDER) {
            $payment->setNotes(sprintf('User was requested to pay the remainder of their policy'));
        }
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // add payment
        /** @var Judopay\Model $webPayment */
        $webPayment = $this->webClient->getModel('WebPayments\Payment');

        // populate the required data fields.
        $webPayment->setAttributeValues(
            array(
                'judoId' => $this->judoId,
                'yourConsumerReference' => $policy->getUser()->getId(),
                'yourPaymentReference' => $payment->getId(),
                'yourPaymentMetaData' => [
                    'policy_id' => $policy->getId(),
                    'web_type' => $type ? $type : null,
                ],
                'amount' => $this->toTwoDp($amount),
                'currency' => 'GBP',
                'clientIpAddress' => $ipAddress,
                'clientUserAgent' => $userAgent,
            )
        );

        $webpaymentDetails = $webPayment->create();
        $this->logger->info(sprintf('Judo Webpayment %s', json_encode($webpaymentDetails)));
        $payment->setReference($webpaymentDetails["reference"]);

        $policy->addPayment($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return array('post_url' => $webpaymentDetails["postUrl"], 'payment' => $payment);
    }

    public function webRegister(User $user, $ipAddress, $userAgent, Policy $policy = null)
    {
        $payment = new JudoPayment();
        $payment->setAmount(0);
        $payment->setUser($user);
        $payment->setSource(Payment::SOURCE_WEB);
        $payment->setWebType(self::WEB_TYPE_CARD_DETAILS);
        if ($policy) {
            $payment->setPolicy($policy);
        }
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        /** @var Judopay\Model $webPreAuth */
        $webPreAuth = $this->webClient->getModel('WebPayments\Preauth');
        $date = \DateTime::createFromFormat('U', time());
        $paymentRef = sprintf('%s-%s', $user->getId(), $date->format('Ym'));

        // populate the required data fields.
        $webPreAuth->setAttributeValues(
            array(
                'judoId' => $this->judoId,
                'yourConsumerReference' => $user->getId(),
                'yourPaymentReference' => $paymentRef,
                'amount' => '1.01',
                'currency' => 'GBP',
                'clientIpAddress' => $ipAddress,
                'clientUserAgent' => $userAgent,
                'webPaymentOperation' => 'register',
                'yourPaymentMetaData' => [
                    'web_type' => self::WEB_TYPE_CARD_DETAILS,
                ],
            )
        );

        $webpaymentDetails = $webPreAuth->create();
        $this->logger->info(sprintf('Judo Webpayment %s', json_encode($webpaymentDetails)));
        $payment->setReference($webpaymentDetails["reference"]);

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return array('post_url' => $webpaymentDetails["postUrl"], 'payment' => $payment);
    }

    /**
     * Refund a payment
     *
     * @param JudoPayment $payment
     * @param float       $amount         Amount to refund (or null for entire initial amount)
     * @param float       $totalCommision Total commission amount to refund (or null for entire amount from payment)
     * @param string      $notes
     * @param string      $source
     *
     * @return JudoPayment
     */
    public function refund(JudoPayment $payment, $amount = null, $totalCommision = null, $notes = null, $source = null)
    {
        if (!$amount) {
            $amount = $payment->getAmount();
        }
        if (!$totalCommision) {
            $totalCommision = $payment->getTotalCommission();
        }
        $policy = $payment->getPolicy();

        // Refund is a negative payment
        $refund = new JudoPayment();
        $refund->setAmount(0 - $amount);
        $refund->setNotes($notes);
        $refund->setSource($source);
        if ($policy->getPolicyOrPayerOrUserJudoPaymentMethod()) {
            $payment->setDetails($policy->getPolicyOrPayerOrUserJudoPaymentMethod()->__toString());
        }
        $policy->addPayment($refund);
        $this->dm->persist($refund);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // add refund
        /** @var Judopay\Model $refundModel */
        $refundModel = $this->apiClient->getModel('Refund');

        $data = array(
                'judoId' => $this->judoId,
                'receiptId' => $payment->getReceipt(),
                'yourPaymentReference' => $refund->getId(),
                'amount' => $this->toTwoDp(abs($refund->getAmount())),
        );

        // populate the required data fields.
        $refundModel->setAttributeValues($data);

        try {
            $refundModelDetails = $refundModel->create();
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Error running refund %s (%0.2f >? %0.2f) Data: %s',
                $refund->getId(),
                $this->toTwoDp(abs($refund->getAmount())),
                $payment->getAmount(),
                json_encode($data)
            ), ['exception' => $e]);

            throw $e;
        }

        // seems like in the past, potentially  the refund receipt is the same receipt id as the initial payment
        // I'm not sure if this is still the case, however, we can add a prefix if it exists in the DB, just in case
        $receiptId = $refundModelDetails["receiptId"];
        $repo = $this->dm->getRepository(Payment::class);
        $payment = $repo->findOneBy(['receiptId' => $receiptId]);
        if ($payment) {
            $receiptId = sprintf('R-%s', $receiptId);
        }

        $refund->setReference($refundModelDetails["yourPaymentReference"]);
        $refund->setReceipt($receiptId);
        $refund->setAmount(0 - $refundModelDetails["amount"]);
        $refund->setResult($refundModelDetails["result"]);
        $refund->setMessage($refundModelDetails["message"]);
        if (isset($refundModelDetails["riskScore"])) {
            $refund->setRiskScore($refundModelDetails["riskScore"]);
        }

        $refund->setRefundTotalCommission($totalCommision);

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return $refund;
    }

    public function processCsv(JudoFile $judoFile)
    {
        $filename = $judoFile->getFile();
        $header = null;
        $lines = array();
        $dailyTransaction = array();

        $payments = 0;
        $numPayments = 0;
        $refunds = 0;
        $numRefunds = 0;
        $declined = 0;
        $numDeclined = 0;
        $failed = 0;
        $numFailed = 0;
        $total = 0;
        $maxDate = null;
        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000)) !== false) {
                if (!$header) {
                    $header = $row;
                } else {
                    $line = array_combine($header, $row);
                    $lines[] = $line;
                    $transactionDate = \DateTime::createFromFormat(
                        'd F Y H:i',
                        $line['Date'],
                        SoSure::getSoSureTimezone()
                    );
                    if (!$transactionDate) {
                        throw new \Exception(sprintf('Unable to parse date %s', $line['Date']));
                    }
                    $transactionDate = self::convertTimezone($transactionDate, new \DateTimeZone('UTC'));

                    if (!isset($dailyTransaction[$transactionDate->format('Ymd')])) {
                        $dailyTransaction[$transactionDate->format('Ymd')] = 0;
                    }

                    if ($line['TransactionResult'] == "Transaction Successful") {
                        if ($line['TransactionType'] == "Payment") {
                            $total += $line['Net'];
                            $payments += $line['Net'];
                            $numPayments++;
                            $dailyTransaction[$transactionDate->format('Ymd')] += $line['Net'];
                        } elseif ($line['TransactionType'] == "Refund") {
                            $total -= $line['Net'];
                            $refunds += $line['Net'];
                            $numRefunds++;
                            $dailyTransaction[$transactionDate->format('Ymd')] -= $line['Net'];
                        }
                    } elseif ($line['TransactionResult'] == "Card Declined") {
                        $declined += $line['Net'];
                        $numDeclined++;
                    } elseif ($line['TransactionResult'] == "Failed") {
                        $failed += $line['Net'];
                        $numFailed++;
                    } elseif (mb_strlen(trim($line['TransactionResult'])) == 0) {
                        $failed += $line['Net'];
                        $numFailed++;

                        // @codingStandardsIgnoreStart
                        $body = sprintf(
                            'Our csv export for last month included a blank transaction result for receipt %s. Please confirm the transaction was, in fact, a failure.',
                            $line['ReceiptId']
                        );
                        // @codingStandardsIgnoreEnd

                        $this->mailer->send(
                            sprintf('Missing Transaction Result for Receipt %s', $line['ReceiptId']),
                            'developersupport@judopayments.com',
                            $body,
                            null,
                            null,
                            'tech@so-sure.com',
                            'tech@so-sure.com'
                        );
                    } else {
                        throw new \Exception(sprintf('Unknown Transaction Result: %s', $line['TransactionResult']));
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
            'declined' => $this->toTwoDp($declined),
            'numDeclined' => $numDeclined,
            'failed' => $this->toTwoDp($failed),
            'numFailed' => $numFailed,
            'dailyTransaction' => $dailyTransaction,
            'data' => $lines,
        ];

        $judoFile->addMetadata('total', $data['total']);
        $judoFile->addMetadata('payments', $data['payments']);
        $judoFile->addMetadata('numPayments', $data['numPayments']);
        $judoFile->addMetadata('refunds', $data['refunds']);
        $judoFile->addMetadata('numRefunds', $data['numRefunds']);
        $judoFile->addMetadata('declined', $data['declined']);
        $judoFile->addMetadata('numDeclined', $data['numDeclined']);
        $judoFile->addMetadata('failed', $data['failed']);
        $judoFile->addMetadata('numFailed', $data['numFailed']);
        $judoFile->setDailyTransaction($data['dailyTransaction']);
        $judoFile->setDate($data['date']);

        return $data;
    }

    /**
     * @param MultiPay  $multiPay
     * @param float     $amount
     * @param \DateTime $date
     */
    public function multiPay(MultiPay $multiPay, $amount, \DateTime $date = null)
    {
        $this->statsd->startTiming("judopay.multipay");

        $policy = $multiPay->getPolicy();
        if ($policy->getStatus() != PhonePolicy::STATUS_MULTIPAY_REQUESTED) {
            throw new ProcessedException();
        }

        // Policy should NOT change to pending as will affect client
        // Monitoring should be set on any policies with STATUS_MULTIPAY_REQUESTED
        // that also have a multi pay set to aceepted
        // $policy->setStatus(PhonePolicy::STATUS_PENDING);
        $multiPay->getPayer()->addPayerPolicy($policy);
        $this->dm->flush();

        $payment = $this->tokenPay($policy, $amount, null, false);
        if (!$payment->isSuccess()) {
            return false;
        }

        $this->policyService->create($policy, $date, true);
        $this->dm->flush();

        $this->statsd->endTiming("judopay.multipay");

        return true;
    }

    /**
     *
     * @param Policy    $policy
     * @param double    $amount
     * @param \DateTime $date
     */
    public function existing(Policy $policy, $amount, \DateTime $date = null)
    {
        $this->statsd->startTiming("judopay.existing");

        $premium = $policy->getPremium();
        if ($amount < $premium->getMonthlyPremiumPrice() &&
            !$this->areEqualToTwoDp($amount, $premium->getMonthlyPremiumPrice())) {
            throw new InvalidPremiumException();
        } elseif ($amount > $premium->getYearlyPremiumPrice() &&
            !$this->areEqualToTwoDp($amount, $premium->getYearlyPremiumPrice())) {
            throw new InvalidPremiumException();
        }

        if (!$policy->getPayer()) {
            $policy->setPayer($policy->getUser());
        }

        $payment = $this->tokenPay($policy, $amount, null, false);
        if (!$payment->isSuccess()) {
            return false;
        }

        $this->policyService->create($policy, $date, true);
        $this->dm->flush();

        $this->statsd->endTiming("judopay.existing");

        return true;
    }
}
