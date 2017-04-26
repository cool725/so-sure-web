<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

use JudoPay;

use AppBundle\Classes\Salva;

use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Payment;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\MultiPay;
use AppBundle\Document\CurrencyTrait;

use AppBundle\Event\PaymentEvent;

use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\PaymentDeclinedException;
use AppBundle\Exception\ProcessedException;

class JudopayService
{
    use CurrencyTrait;

    /** @var LoggerInterface */
    protected $logger;

    /** @var JudoPay */
    protected $apiClient;

    /** @var JudoPay */
    protected $webClient;

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

    protected $dispatcher;

    /** @var SmsService */
    protected $sms;

    public function setDispatcher($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

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
     * @param string          $webToken
     * @param string          $webSecret
     * @param                 $dispatcher
     * @param SmsService      $sms
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
        $statsd,
        $webToken,
        $webSecret,
        $dispatcher,
        SmsService $sms
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
    }

    public function getTransactions($pageSize)
    {
        $repo = $this->dm->getRepository(JudoPayment::class);
        $transactions = $this->apiClient->getModel('Transaction');
        $data = array(
            'judoId' => $this->judoId,
        );

        $transactions->setAttributeValues($data);
        $details = $transactions->all(0, $pageSize);
        $result = [
            'validated' => 0,
            'missing' => 0,
            'non-payment' => 0,
            'skipped' => 0,
        ];
        foreach ($details['results'] as $receipt) {
            $created = new \DateTime($receipt['createdAt']);
            $now = new \DateTime();
            $diff = $now->getTimestamp() - $created->getTimestamp();
            // allow a few (5) minutes before warning if missing receipt
            if ($diff < 300) {
                $result['skipped']++;
            } elseif ($receipt['type'] == 'Payment') {
                $payment = $repo->findOneBy(['receipt' => $receipt['receiptId']]);
                if (!$payment) {
                    // Only need to be concerned about successful payments
                    if ($receipt['result'] == JudoPayment::RESULT_SUCCESS) {
                        $this->logger->warning(sprintf(
                            'Missing judo payment item for receipt %s on %s [%s]',
                            $receipt['receiptId'],
                            $receipt['createdAt'],
                            json_encode($receipt)
                        ));
                    }
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
     * @param string    $source        Source of the payment
     * @param string    $deviceDna     Optional device dna data (json encoded) for judoshield
     * @param \DateTime $date
     */
    public function add(
        Policy $policy,
        $receiptId,
        $consumerToken,
        $cardToken,
        $source,
        $deviceDna = null,
        \DateTime $date = null
    ) {
        $this->statsd->startTiming("judopay.add");
        // doesn't make sense to add payments for cancelled or expired policies
        if (in_array($policy->getStatus(), [PhonePolicy::STATUS_CANCELLED, PhonePolicy::STATUS_EXPIRED])) {
            throw new \Exception('Unable to apply payment to cancelled/expired policy');
        }

        // A bit strange for payment to be applied to an active policy
        // but make sure we credit that policy anyway
        if ($policy->getStatus() == PhonePolicy::STATUS_ACTIVE) {
            $this->logger->warning(sprintf(
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

            $this->createPayment($policy, $receiptId, $consumerToken, $cardToken, $source, $deviceDna, $date);

            $this->policyService->create($policy, $date, true);
            $this->dm->flush();
        } else {
            // Existing policy - add payment + prevent duplicate billing
            $this->createPayment($policy, $receiptId, $consumerToken, $cardToken, $source, $deviceDna, $date);
            if (!$this->policyService->adjustScheduledPayments($policy, $date)) {
                // Reload object from db
                $policy = $this->dm->merge($policy);
            }

            $this->validatePolicyStatus($policy, $date);
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
        $user->setPaymentMethod($judo);

        $payment = $this->validateReceipt($policy, $receiptId, $cardToken, $source, $date);
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

        $this->validateUser($user);
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
            $payment = $this->apiClient->getModel('CardPayment');
            $payment->setAttributeValues($data);
            $details = $payment->create();
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending test payment: %s', json_encode($dataCopy)),
                ['exception' => $e]
            );

            // retry
            $payment = $this->apiClient->getModel('CardPayment');
            $payment->setAttributeValues($dataCopy);
            $details = $payment->create();
        }

        return $details;
    }

    public function testRegisterDetails(User $user, $ref, $cardNumber, $expiryDate, $cv2)
    {
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

    public function getReceipt($receiptId)
    {
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

        return $transactionDetails;
    }

    /**
     * @param User   $user
     * @param string $receiptId
     * @param string $consumerToken
     * @param string $cardToken     Can be null if card is declined
     * @param string $deviceDna     Optional device dna data (json encoded) for judoshield
     */
    public function updatePaymentMethod(
        User $user,
        $receiptId,
        $consumerToken,
        $cardToken,
        $deviceDna = null
    ) {
        $transactionDetails = $this->getReceipt($receiptId);
        if ($transactionDetails["result"] != JudoPayment::RESULT_SUCCESS) {
            throw new PaymentDeclinedException();
        }

        $judo = $user->getPaymentMethod();
        if (!$judo) {
            $judo = new JudoPaymentMethod();
            $user->setPaymentMethod($judo);
        }
        $judo->setCustomerToken($consumerToken);
        if ($cardToken) {
            $judo->addCardToken($cardToken, json_encode($transactionDetails['cardDetails']));
        }
        if ($deviceDna) {
            $judo->setDeviceDna($deviceDna);
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
        $payment = $repo->find($transactionDetails["yourPaymentReference"]);
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
                'ADJUSTMENT NEEDED!! Expected %f or %f, not %f for payment id: %s',
                $premium->getMonthlyPremiumPrice(),
                $premium->getYearlyPremiumPrice(),
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
            $payment = $this->tokenPay($policy, $scheduledPayment->getAmount(), $scheduledPayment->getType());
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
            // print 'status -> active' . PHP_EOL;
            // \Doctrine\Common\Util\Debug::dump($policy);
        } elseif (!$isPaidToDate) {
            $this->logger->error(sprintf('Policy %s is not paid to date', $policy->getPolicyNumber()));

            if (in_array($policy->getStatus(), [PhonePolicy::STATUS_ACTIVE, PhonePolicy::STATUS_PENDING])) {
                $policy->setStatus(PhonePolicy::STATUS_UNPAID);
            }
        }
    }

    public function processScheduledPaymentResult($scheduledPayment, $payment, \DateTime $date = null)
    {
        $policy = $scheduledPayment->getPolicy();
        $scheduledPayment->setPayment($payment);
        if ($payment->getResult() == JudoPayment::RESULT_SUCCESS) {
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

            $repo = $this->dm->getRepository(ScheduledPayment::class);

            // Only allow up to 4 failed payment attempts
            $failedPayments = $repo->countUnpaidScheduledPayments($policy);

            $finalAttempt = $failedPayments == 4;
            $next = null;
            if ($failedPayments <= 3) {
                // create another scheduled payment for 7 days later
                $rescheduled = $scheduledPayment->reschedule($date);
                $policy->addScheduledPayment($rescheduled);
                $this->dm->flush(null, array('w' => 'majority', 'j' => true));
                $next = $rescheduled->getScheduled();
            }

            $this->failedPaymentEmail($policy, $next);
            // Sms is quite invasive and occasionlly a failed payment will just work the next time
            // so allow 1 failed payment before sending sms
            if ($failedPayments > 1) {
                $this->failedPaymentSms($policy, $next);
            }
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
            $nextMonth = new \DateTime();
        } else {
            $nextMonth = clone $date;
        }
        $nextMonth->add(new \DateInterval('P1M'));

        if (!$policy->getUser()->getPaymentMethod()->isCardExpired($nextMonth)) {
            return false;
        }

        $baseTemplate = sprintf('AppBundle:Email:policy/cardExpiring');
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $this->mailer->sendTemplate(
            sprintf('Your card is expiring next month'),
            $policy->getUser()->getEmail(),
            $htmlTemplate,
            ['policy' => $policy],
            $textTemplate,
            ['policy' => $policy]
        );

        return true;
    }

    /**
     * @param Policy    $policy
     * @param \DateTime $next
     */
    private function failedPaymentEmail(Policy $policy, \DateTime $next = null)
    {
        $subject = sprintf('Payment failure for your so-sure policy %s', $policy->getPolicyNumber());
        $baseTemplate = sprintf('AppBundle:Email:policy/failedPayment');

        if (!$next) {
            $subject = sprintf('Payment failure for your so-sure policy %s', $policy->getPolicyNumber());
            $baseTemplate = sprintf('AppBundle:Email:policy/failedPaymentFinal');
        }

        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $this->mailer->sendTemplate(
            $subject,
            $policy->getUser()->getEmail(),
            $htmlTemplate,
            ['policy' => $policy, 'next' => $next],
            $textTemplate,
            ['policy' => $policy, 'next' => $next]
        );
    }

    /**
     * @param Policy    $policy
     * @param \DateTime $next
     */
    private function failedPaymentSms(Policy $policy, \DateTime $next = null)
    {
        if ($this->environment != 'prod') {
            return;
        }

        $smsTemplate = 'AppBundle:Sms:failedPayment.txt.twig';
        if (!$next) {
            $smsTemplate = 'AppBundle:Sms:failedPaymentFinal.txt.twig';
        }
        $this->sms->sendUser($policy, $smsTemplate, ['policy' => $policy, 'next' => $next]);
    }

    public function runTokenPayment(User $user, $amount, $paymentRef, $policyId, $customerRef = null)
    {
        $paymentMethod = $user->getPaymentMethod();
        if (!$paymentMethod) {
            throw new \Exception(sprintf('Unknown payment method for user %s', $user->getId()));
        }
        if (!$customerRef) {
            $customerRef = $user->getId();
        }

        // add payment
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
                'emailAddress' => $user->getEmail(),
                'mobileNumber' => $user->getMobileNumber(),
        );
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

    protected function tokenPay(Policy $policy, $amount = null, $notes = null)
    {
        if (!$amount) {
            $amount = $policy->getPremium()->getMonthlyPremiumPrice();
        }
        $user = $policy->getPayer();

        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $payment->setNotes($notes);
        $payment->setUser($policy->getUser());
        $payment->setSource(Payment::SOURCE_TOKEN);
        $policy->addPayment($payment);
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        if ($user->hasValidPaymentMethod()) {
            $tokenPaymentDetails = $this->runTokenPayment($user, $amount, $payment->getId(), $policy->getId());

            $payment->setReference($tokenPaymentDetails["yourPaymentReference"]);
            $payment->setReceipt($tokenPaymentDetails["receiptId"]);
            $payment->setAmount($tokenPaymentDetails["amount"]);
            $payment->setResult($tokenPaymentDetails["result"]);
            $payment->setMessage($tokenPaymentDetails["message"]);
            if (isset($tokenPaymentDetails["riskScore"])) {
                $payment->setRiskScore($tokenPaymentDetails["riskScore"]);
            }
        } else {
            $this->logger->warning(sprintf(
                'User %s does not have a valid payment method (Policy %s)',
                $user->getId(),
                $policy->getId()
            ));
            $payment->setResult(JudoPayment::RESULT_SKIPPED);
        }

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // Ensure the correct amount is paid
        $this->validatePaymentAmount($payment);

        $this->setCommission($policy, $payment);

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
    public function webpay(Policy $policy, $amount, $ipAddress, $userAgent)
    {
        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $payment->setUser($policy->getUser());
        $payment->setSource(Payment::SOURCE_WEB);
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // add payment
        $webPayment = $this->webClient->getModel('WebPayments\Payment');

        // populate the required data fields.
        $webPayment->setAttributeValues(
            array(
                'judoId' => $this->judoId,
                'yourConsumerReference' => $policy->getUser()->getId(),
                'yourPaymentReference' => $payment->getId(),
                'yourPaymentMetaData' => [
                    'policy_id' => $policy->getId(),
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

    public function webRegister(User $user, $ipAddress, $userAgent)
    {
        $payment = new JudoPayment();
        $payment->setAmount(0);
        $payment->setUser($user);
        $payment->setSource(Payment::SOURCE_WEB);
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        $webPreAuth = $this->webClient->getModel('WebPayments\Preauth');
        $date = new \DateTime();
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

        // Refund is a negative payment
        $refund = new JudoPayment();
        $refund->setAmount(0 - $amount);
        $refund->setNotes($notes);
        $refund->setSource($source);
        $payment->getPolicy()->addPayment($refund);
        $this->dm->persist($refund);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // add refund
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
            $this->logger->error(sprintf('Error running refund %s', $refund->getId()), ['exception' => $e]);

            throw $e;
        }

        $refund->setReference($refundModelDetails["yourPaymentReference"]);
        // as refund receipt is the same, add prefix to prevent duplciates in db, so we can have unique index
        $refund->setReceipt(sprintf('R-%s', $refundModelDetails["receiptId"]));
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

    public function processCsv($judoFile)
    {
        $filename = $judoFile->getFile();
        $header = null;
        $lines = array();
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
                    if ($line['TransactionResult'] == "Transaction Successful") {
                        if ($line['TransactionType'] == "Payment") {
                            $total += $line['Net'];
                            $payments += $line['Net'];
                            $numPayments++;
                        } elseif ($line['TransactionType'] == "Refund") {
                            $total -= $line['Net'];
                            $refunds += $line['Net'];
                            $numRefunds++;
                        }
                    } elseif ($line['TransactionResult'] == "Card Declined") {
                        $declined += $line['Net'];
                        $numDeclined++;
                    } elseif ($line['TransactionResult'] == "Failed") {
                        $failed += $line['Net'];
                        $numFailed++;
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
        $judoFile->setDate($data['date']);

        return $data;
    }

    /**
     * @param MultiPay  $multiPay
     * @param           $amount
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

        $this->tokenPay($policy, $amount);

        $this->policyService->create($policy, $date, true);
        $this->dm->flush();

        $this->statsd->endTiming("judopay.multipay");

        return true;
    }

    /**
     * @param Policy    $policy
     * @param \DateTime $date
     */
    public function multiPolicy(Policy $policy, \DateTime $date = null)
    {
        $this->statsd->startTiming("judopay.multipolicy");

        if ($policy->getStatus() != null) {
            throw new ProcessedException();
        }

        if (!$policy->getPayer()) {
            $policy->setPayer($policy->getUser());
        }

        $this->tokenPay($policy);

        $this->policyService->create($policy, $date, true);
        $this->dm->flush();

        $this->statsd->endTiming("judopay.multipolicy");

        return true;
    }
}
