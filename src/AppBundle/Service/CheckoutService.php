<?php
namespace AppBundle\Service;

use AppBundle\Classes\NoOp;
use AppBundle\Classes\SoSure;
use AppBundle\Document\Charge;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\CheckoutFile;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Exception\CommissionException;
use AppBundle\Exception\DuplicatePaymentException;
use AppBundle\Exception\InvalidPaymentMethodException;
use AppBundle\Exception\ScheduledPaymentException;
use AppBundle\Repository\CheckoutPaymentRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use Checkout\CheckoutApi;
use com\checkout\ApiClient;
use com\checkout\ApiServices\Cards\RequestModels\BaseCardCreate;
use com\checkout\ApiServices\Cards\RequestModels\CardCreate;
use com\checkout\ApiServices\Cards\ResponseModels\Card;
use com\checkout\ApiServices\Charges\RequestModels\CardChargeCreate;
use com\checkout\ApiServices\Charges\RequestModels\CardIdChargeCreate;
use com\checkout\ApiServices\Charges\RequestModels\CardTokenChargeCreate;
use com\checkout\ApiServices\Charges\RequestModels\ChargeCapture;
use com\checkout\ApiServices\Charges\RequestModels\ChargeRefund;
use com\checkout\ApiServices\Customers\RequestModels\CustomerCreate;
use com\checkout\ApiServices\Reporting\RequestModels\TransactionFilter;
use com\checkout\ApiServices\SharedModels\Address;
use com\checkout\ApiServices\SharedModels\Transaction;
use com\checkout\ApiServices\Tokens\RequestModels\PaymentTokenCreate;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

use AppBundle\Classes\Salva;

use AppBundle\Document\Payment\Payment;
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

class CheckoutService
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

    /** @var ApiClient */
    protected $client;

    /** @var CheckoutApi */
    protected $api;

    public function setDispatcher($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param DocumentManager          $dm
     * @param LoggerInterface          $logger
     * @param PolicyService            $policyService
     * @param MailerService            $mailer
     * @param string                   $apiSecret
     * @param string                   $apiPublic
     * @param string                   $environment
     * @param \Domnikl\Statsd\Client   $statsd
     * @param EventDispatcherInterface $dispatcher
     * @param SmsService               $sms
     * @param FeatureService           $featureService
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        PolicyService $policyService,
        MailerService $mailer,
        $apiSecret,
        $apiPublic,
        $environment,
        \Domnikl\Statsd\Client $statsd,
        EventDispatcherInterface $dispatcher,
        SmsService $sms,
        FeatureService $featureService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->policyService = $policyService;
        $this->mailer = $mailer;
        $this->dispatcher = $dispatcher;
        $this->sms = $sms;
        $this->statsd = $statsd;
        $this->environment = $environment;
        $this->featureService = $featureService;

        $isProd = $environment == 'prod';
        $this->client = new ApiClient($apiSecret, $isProd ? 'live' : 'sandbox', !$isProd);
        $this->api = new CheckoutApi($apiSecret, -1, $apiPublic);
    }

    /**
     * @param string $chargeId
     * @return \com\checkout\ApiServices\Charges\ResponseModels\Charge
     */
    public function getTransaction($chargeId)
    {
        $charge = $this->client->chargeService();
        /** @var \com\checkout\ApiServices\Charges\ResponseModels\Charge $details */
        $details = $charge->getCharge($chargeId);

        return $details;
    }

    public function getTransactionWebType($chargeId)
    {
        try {
            $data = $this->getTransaction($chargeId);
            if ($data->getMetadata() && isset($data->getMetadata()['web_type'])) {
                return $data->getMetadata()['web_type'];
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                sprintf('Unable to find transaction charge %s', $chargeId),
                ['exception' => $e]
            );
        }

        return null;
    }

    public function getTransactions($pageSize, $logMissing = true)
    {
        NoOp::ignore([$pageSize]);

        $policies = [];
        $repo = $this->dm->getRepository(CheckoutPayment::class);

        $filter = new TransactionFilter();
        $filter->setPageSize($pageSize);
        //$filter->setSearch()

        $transactions = $this->client->reportingService()->queryTransaction($filter);
        $data = [
            'validated' => 0,
            'missing' => [],
            'invalid' => [],
            'non-payment' => 0,
            'skipped-too-soon' => 0,
            'additional-payments' => []
        ];
        foreach ($transactions->getData() as $transaction) {
            /** @var Transaction $transaction */
            $policyId = null;
            $result = $transaction->getStatus();
            $details = $this->getTransaction($transaction->getId());
            if ($result == CheckoutPayment::RESULT_CAPTURED &&
                $details->getMetadata() && isset($details->getMetadata()['policy_id'])) {
                // Non-token payments (eg. user) may be tried several times in a row
                // Ideally would seperate out the user/token payments, but for now
                // use success as a proxy for that
                $policyId = $details->getMetadata()['policy_id'];
                if (!isset($policies[$policyId])) {
                    $policies[$policyId] = true;
                } else {
                    if (!isset($data['additional-payments'][$policyId])) {
                        $data['additional-payments'][$policyId] = 0;
                    }
                    //$data['additional-payments'][$policyId]++;
                    $data['additional-payments'][$policyId] = json_encode($transaction->getObject());
                }
            }

            $chargeId = $transaction->getId();
            /** @var CheckoutPayment $payment */
            $payment = $repo->findOneBy(['receipt' => $chargeId]);

            $created = null;
            if ($transaction->getDate()) {
                $created = \DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $transaction->getDate());
            }

            if (!$created) {
                $created = $this->now();
            }
            $now = \DateTime::createFromFormat('U', time());
            $diff = $now->getTimestamp() - $created->getTimestamp();

            $success = CheckoutPayment::isSuccessfulResult($transaction->getStatus());

            // allow a few (5) minutes before warning if missing receipt
            if ($diff < 300) {
                $data['skipped-too-soon']++;
            } elseif ($success) {
                if (!$payment && $details->getValue() != 0) {
                    if ($logMissing) {
                        $this->logger->error(sprintf(
                            'INVESTIGATE!! Missing db checkout payment for received payment. id %s on %s [%s]',
                            $chargeId,
                            $transaction->getDate(),
                            json_encode($transaction->getObject())
                        ));
                    }
                    $data['missing'][$chargeId] = $transaction->getTrackId();
                } elseif ($payment && !$payment->isSuccess()) {
                    if ($logMissing) {
                        $this->logger->error(sprintf(
                            'INVESTIGATE!! Checkout payment status in db does not match checkout id %s on %s [%s]',
                            $chargeId,
                            $transaction->getDate(),
                            json_encode($transaction->getObject())
                        ));
                    }
                    $data['invalid'][$chargeId] = $transaction->getTrackId();
                } else {
                    $data['validated']++;
                }
            } elseif (!$success) {
                // can ignore failed missing payments
                // however if our db thinks it successful and judo says its not, that's problematic
                if ($payment && $payment->isSuccess()) {
                    if ($logMissing) {
                        $this->logger->error(sprintf(
                            'INVESTIGATE!! Checkout payment status in db does not match checkout. id %s on %s [%s]',
                            $chargeId,
                            $transaction->getDate(),
                            json_encode($transaction->getObject())
                        ));
                    }
                    $data['invalid'][$chargeId] = $transaction->getTrackId();
                }
            } else {
                $data['non-payment']++;
            }
        }

        return $data;
    }

    public function pay(
        Policy $policy,
        $token,
        $amount,
        $source,
        \DateTime $date = null,
        IdentityLog $identityLog = null
    ) {
        $charge = $this->capturePaymentMethod($policy, $token, $amount);
        if ($charge->getRedirectUrl()) {
            return $charge;
        }
        return $this->add($policy, $charge->getId(), $source, $date, $identityLog);
    }

    /**
     * @param Policy      $policy
     * @param string      $chargeId
     * @param string      $source      Source of the payment
     * @param \DateTime   $date
     * @param IdentityLog $identityLog
     */
    public function add(
        Policy $policy,
        $chargeId,
        $source,
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
        /** @var ScheduledPaymentRepository $scheduledPaymentRepo */
        $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
        $payment = null;
        if (!$policy->getStatus() ||
            in_array($policy->getStatus(), [PhonePolicy::STATUS_PENDING, PhonePolicy::STATUS_MULTIPAY_REJECTED])) {
            // New policy
            // Mark policy as pending for monitoring purposes
            $policy->setStatus(PhonePolicy::STATUS_PENDING);
            $this->dm->flush();
            $payment = $this->createPayment(
                $policy,
                $chargeId,
                $source,
                $date
            );
            $this->policyService->create($policy, $date, true, null, $identityLog);
            $this->dm->flush();
        } else {
            // Existing policy - add payment + prevent duplicate billing
            $payment = $this->createPayment(
                $policy,
                $chargeId,
                $source,
                $date
            );
            /**
             * We also want to make sure that the payment updates any unpaid customer to active.
             * This has not always been happening, so we will do it here.
             */
            if ($policy->isPolicyPaidToDate()) {
                $policy->setPolicyStatusActiveIfUnpaid();
                $this->dm->flush();
                /**
                 * We also want to check if there are any scheduled payments in the past
                 * that have not been updated. So, we will check for that here and
                 * cancel any with a status of 'scheduled'.
                 */
                if ($policy->getLastSuccessfulUserPaymentCredit()) {
                    $lastSuccess = $policy->getLastSuccessfulUserPaymentCredit()->getDate();
                    $oldUnpaid = $scheduledPaymentRepo->getPastScheduledWithNoStatusUpdate($policy, $lastSuccess);
                    /** @var ScheduledPayment $scheduledPayment */
                    foreach ($oldUnpaid as $scheduledPayment) {
                        $scheduledPayment->cancel('Cancelling old scheduled as payment made to bring up to date');
                    }
                    $this->dm->flush();
                }
            }
            if (!$this->policyService->adjustScheduledPayments($policy, true)) {
                // Reload object from db
                /** @var Policy $policy */
                $policy = $this->dm->merge($policy);
            }
            $this->validatePolicyStatus($policy, $date);
            $this->dm->flush();
        }
        // Make sure upcoming rescheduled scheduled payments are now cancelled.
        if ($payment && $payment->getAmount() > 0) {
            $rescheduledPayments = $scheduledPaymentRepo->findRescheduled($policy);
            foreach ($rescheduledPayments as $rescheduled) {
                $rescheduled->cancel('Cancelled rescheduled payment as web payment made');
            }
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
        $chargeId,
        $source,
        \DateTime $date = null
    ) {
        $user = $policy->getUser();

        $payment = $this->validateCharge($policy, $chargeId, $source, $date);

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

    public function testPay(Policy $policy, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId = null)
    {
        $charge = $this->testPayDetails($policy, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId);
        if (!$charge) {
            return null;
        }

        return $charge->getId();
    }

    private function getCheckoutAddress(User $user)
    {
        $address = new Address();
        if ($user->getBillingAddress()) {
            $address->setAddressLine1($user->getBillingAddress()->getLine1());
            $address->setAddressLine2($user->getBillingAddress()->getLine2());
            $address->setCity($user->getBillingAddress()->getCity());
            $address->setPostcode($user->getBillingAddress()->getPostcode());
        }
        $address->setCountry('GB');

        $phone = new \com\checkout\ApiServices\SharedModels\Phone();
        $phone->setNumber(str_replace('+44', '', $user->getMobileNumber()));
        $phone->setCountryCode('44');
        $address->setPhone($phone);

        return $address;
    }

    /**
     * @param Policy      $policy
     * @param string      $ref
     * @param string      $amount
     * @param string      $cardNumber
     * @param string      $expiryDate
     * @param string      $cv2
     * @param string|null $policyId
     * @return \com\checkout\ApiServices\Charges\ResponseModels\Charge|null
     * @throws \Exception
     */
    public function testPayDetails(Policy $policy, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId = null)
    {
        /** @var CheckoutPaymentMethod $paymentMethod */
        $paymentMethod = $policy->getCheckoutPaymentMethod();
        if (!$paymentMethod) {
            $paymentMethod = new CheckoutPaymentMethod();
            $policy->setPaymentMethod($paymentMethod);
        }
        $user = $policy->getUser();
        $details = null;
        try {
            $exp = explode('/', $expiryDate);


            $cardCreate = new BaseCardCreate();
            $cardCreate->setNumber(str_replace(' ', '', $cardNumber));
            $cardCreate->setExpiryMonth($exp[0]);
            $cardCreate->setExpiryYear($exp[1]);
            $cardCreate->setCvv($cv2);

            $cardCreate->setBillingDetails($this->getCheckoutAddress($user));

            /*
            $cardCreate = new CardCreate();
            $cardCreate->setBaseCardCreate($card);
            $cardCreate->setCustomerId($user->getId());

            $cardService = $this->client->cardService();
            $card = $cardService->createCard($cardCreate);

            $customerCreate = new CustomerCreate();
            $customerCreate->setBaseCardCreate($cardCreate);
            $customerCreate->setEmail($user->getEmail());

            $customerService = $this->client->customerService();
            $customerResponse = $customerService->createCustomer($customerCreate);

            $this->setCardToken($policy, $customerResponse->getDefaultCard());

            $details = $this->runTokenPayment($policy, $amount, $ref, $policyId);
            */
            $pennies = $this->convertToPennies($amount);
            $charge = new CardChargeCreate();
            if ($paymentMethod->hasPreviousChargeId()) {
                $charge->setPreviousChargeId($paymentMethod->getPreviousChargeId());
            }
            $charge->setEmail($user->getEmail());
            $charge->setAutoCapTime(0);
            $charge->setAutoCapture('N');
            $charge->setValue($pennies);
            $charge->setCurrency('GBP');
            $charge->setTrackId($ref);
            // Don't use for testing - ids will be changing constantly
            //$charge->setCustomerId($user->getId());
            $charge->setMetadata(['policy_id' => $policyId]);
            $charge->setBaseCardCreate($cardCreate);

            $service = $this->client->chargeService();
            $details = $service->chargeWithCard($charge);
            if ($details->getStatus() != CheckoutPayment::RESULT_AUTHORIZED) {
                /**
                 * If the payment was not authorized, we will need to unset the
                 * previousChargeId so that on the next successful payment the
                 * new chargeId is set as previousChargeId for future payments.
                 */
                $paymentMethod->setPreviousChargeId('none');
                $this->dm->flush();
                return $details;
            }

            $capture = new ChargeCapture();
            $capture->setChargeId($details->getId());
            $capture->setValue($this->convertToPennies($amount));

            if ($details) {
                $card = $details->getCard();
                if ($card) {
                    $this->setCardToken($policy, $card);
                }
            }

            if (!$paymentMethod->hasPreviousChargeId()) {
                $paymentMethod->setPreviousChargeId($details->getId());
                $this->dm->flush();
            }

            $service = $this->client->chargeService();
            $details = $service->CaptureCardCharge($capture);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending test payment. Msg: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }

        return $details;
    }

    public function createCardToken($cardNumber, $expiryDate, $cv2)
    {
        $token = null;
        try {
            $exp = explode('/', $expiryDate);

            $cardNumber = str_replace(' ', '', $cardNumber);

            $card = new \Checkout\Models\Tokens\Card($cardNumber, $exp[0], $exp[1]);
            //NoOp::ignore([$cv2]);
            $card->cvv = $cv2;
            $service = $this->api->tokens();
            $token = $service->request($card);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed creating card token. Msg: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }

        return $token;
    }

    public function tokenMigration($filename)
    {
        $skipped = 0;
        $migrated = 0;
        $header = null;
        $repo = $this->dm->getRepository(Policy::class);
        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000)) !== false) {
                if (!$header) {
                    $header = $row;
                } else {
                    $line = array_combine($header, $row);
                    $policies = $repo->findBy(['paymentMethod.cardToken' => $line['old_card_id']]);
                    if (count($policies) == 0) {
                        $this->logger->info(sprintf('Unable to find token %s', $line['old_card_id']));
                        $skipped++;
                        continue;
                    }
                    foreach ($policies as $policy) {
                        /** @var Policy $policy */
                        /** @var \AppBundle\Document\PaymentMethod\JudoPaymentMethod $judo */
                        $judo = $policy->getPolicyOrPayerOrUserJudoPaymentMethod();
                        if (!$judo) {
                            $this->logger->info(sprintf('Unable to find judo payment method %s', $line['old_card_id']));
                            $skipped++;
                            continue;
                        }

                        $cardDetails = [
                            'cardLastFour' => $judo->getCardLastFour(),
                            'endDate' => $judo->getCardEndDate(),
                            'cardType' => $judo->getCardType(),
                        ];

                        $checkout = new CheckoutPaymentMethod();
                        $checkout->setCustomerId($line['cko_customer_id']);
                        $checkout->addCardTokenArray($line['cko_cards_id'], $cardDetails);
                        $checkout->setNotes(sprintf('Was Judo Token: %s', $line['old_card_id']));
                        $policy->setPaymentMethod($checkout);

                        $migrated++;
                    }
                }
            }
        }

        $this->dm->flush();

        return ['migrated' => $migrated, 'skipped' => $skipped];
    }

    public function getCharge($chargeId, $enforceFullAmount = true, $enforceDate = true, \DateTime $date = null)
    {
        $service = $this->client->chargeService();

        try {
            /**  @var \com\checkout\ApiServices\Charges\ResponseModels\ChargeHistory  $transactionDetails **/
            $transactionDetails = $service->getChargeHistory($chargeId);
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Error retrieving charge %s. Ex: %s',
                $chargeId,
                $e
            ));

            throw $e;
        }

        $hasRefund = false;
        $refundedAmount = 0;
        $amount = 0;
        foreach ($transactionDetails->getCharges() as $charge) {
            /** @var \com\checkout\ApiServices\SharedModels\Charge $charge */
            if ($charge->getStatus() == CheckoutPayment::RESULT_REFUNDED) {
                $hasRefund = true;
                $refundedAmount += $charge->getValue();
            } elseif ($charge->getStatus() == CheckoutPayment::RESULT_CAPTURED) {
                $amount += $charge->getValue();
            }
        }


        if ($hasRefund) {
            $msg = sprintf(
                'Checkout receipt %s has a refund applied (refunded %s of %s).',
                $chargeId,
                $this->convertFromPennies($refundedAmount),
                $this->convertFromPennies($amount)
            );
            if ($enforceFullAmount) {
                $this->logger->error($msg);

                throw new \Exception($msg);
            } else {
                $this->logger->warning($msg);
            }
        }


        /**  @var \com\checkout\ApiServices\Charges\ResponseModels\Charge  $transactionDetails **/
        $transactionDetails = $service->verifyCharge($chargeId);
        $created = \DateTime::createFromFormat(\DateTime::ATOM, $transactionDetails->getCreated());

        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $diff = $date->diff($created);
        if ($diff->days > 0 || $diff->h >= self::MAX_HOUR_DELAY_FOR_RECEIPTS) {
            $msg = sprintf(
                'Checkout charge %s is older than expected (%d:%d hours).',
                $chargeId,
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

    public function verifyChargeByToken($token)
    {
        $service = $this->client->chargeService();

        $charge = $service->verifyCharge($token);
        return $charge;
    }

    /**
     * @param Policy $policy
     * @param string $token
     * @param mixed  $amount
     */
    public function capturePaymentMethod(
        Policy $policy,
        $token,
        $amount = null
    ) {
        $user = $policy->getUser();
        $details = null;
        $payment = null;
        /** @var CheckoutPaymentMethod $paymentMethod */
        $paymentMethod = $policy->getCheckoutPaymentMethod();
        if (!$paymentMethod) {
            $paymentMethod = new CheckoutPaymentMethod();
            $policy->setPaymentMethod($paymentMethod);
        }

        try {
            $service = $this->client->chargeService();

            $user = $policy->getUser();

            $charge = new CardTokenChargeCreate();
            if ($paymentMethod->hasPreviousChargeId()) {
                $charge->setPreviousChargeId($paymentMethod->getPreviousChargeId());
            }
            $charge->setEmail($user->getEmail());
            $charge->setAutoCapTime(0);
            $charge->setAutoCapture('N');
            $charge->setCurrency('GBP');
            $charge->setMetadata(['policy_id' => $policy->getId()]);
            $charge->setCardToken($token);
            $charge->setChargeMode(2);
            if ($amount) {
                $charge->setValue($this->convertToPennies($amount));
            }

            $details = $service->chargeWithCardToken($charge);
            $this->logger->info(sprintf('Update Payment Method Resp: %s', json_encode($details)));
            if ($details->getRedirectUrl()) {
                return $details;
            }

            if (!$details || !CheckoutPayment::isSuccessfulResult($details->getStatus(), true)) {
                /**
                 * If the payment was not authorized, we will need to unset the
                 * previousChargeId so that on the next successful payment the
                 * new chargeId is set as previousChargeId for future payments.
                 */
                $paymentMethod->setPreviousChargeId('none');
                $this->dm->flush();
                throw new PaymentDeclinedException($details->getResponseMessage());
            }

            if ($details) {
                $card = $details->getCard();
                if ($card) {
                    $this->setCardToken($policy, $card);
                }
            }

            if ($amount) {
                $capture = new ChargeCapture();
                $capture->setChargeId($details->getId());

                if (!$paymentMethod->hasPreviousChargeId()) {
                    $paymentMethod->setPreviousChargeId($details->getId());
                }

                $details = $service->CaptureCardCharge($capture);
                $this->logger->info(sprintf('Update Payment Method Charge Resp: %s', json_encode($details)));

                if ($details) {
                    $card = $details->getCard();
                    if ($card) {
                        $this->setCardToken($policy, $card);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending test payment. Msg: %s', $e->getMessage()),
                ['exception' => $e]
            );

            throw $e;
        }

        // if a multipay user runs a payment direct on the policy, assume they want to remove multipay
        if ($policy && $policy->isDifferentPayer()) {
            // don't use $user as not validated that policy belongs to user
            $policy->setPayer($policy->getUser());
        }

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return $details;
    }

    /**
     * Stores new card details and starts a new chain of payments.
     * @param Policy     $policy is the policy to whom the new details belong.
     * @param string     $token  is the checkout token with which we can make the request to checkout.
     * @param float|null $amount is an optional amount of money to charge in the same request.
     */
    public function updatePaymentMethod(Policy $policy, $token, $amount = null)
    {
        $throwLater = false;
        $thingToThrow = null;
        $user = $policy->getUser();
        $details = null;
        $payment = null;
        /** @var CheckoutPaymentMethod $paymentMethod */
        $paymentMethod = $policy->getCheckoutPaymentMethod();
        if (!$paymentMethod) {
            $paymentMethod = new CheckoutPaymentMethod();
            $policy->setPaymentMethod($paymentMethod);
        }
        try {
            if ($amount !== null) {
                $payment = new CheckoutPayment();
                $payment->setAmount($amount);
                $payment->setUser($policy->getUser());
                $payment->setSource(Payment::SOURCE_WEB);
                $policy->addPayment($payment);
                $this->dm->persist($payment);
                $this->dm->flush(null, array('w' => 'majority', 'j' => true));
            } else {
                /**
                 * When there is no amount on the card update, we want to unset the previousChargeId so that
                 * on the next payment the new previousChargeId is set.
                 */
                $paymentMethod->setPreviousChargeId('none');
                $this->dm->flush();
            }

            $service = $this->client->chargeService();

            $charge = new CardTokenChargeCreate();
            $charge->setEmail($user->getEmail());
            $charge->setAutoCapTime(0);
            $charge->setAutoCapture('N');
            $charge->setCurrency('GBP');
            $charge->setMetadata(['policy_id' => $policy->getId()]);
            $charge->setCardToken($token);
            if ($amount) {
                $charge->setValue($this->convertToPennies($amount));
            }
            if ($payment) {
                $charge->setTrackId($payment->getId());
            }

            $details = $service->chargeWithCardToken($charge);
            $this->logger->info(sprintf('Update Payment Method Resp: %s', json_encode($details)));

            if ($details && $payment) {
                $payment->setReceipt($details->getId());
                $payment->setAmount($this->convertFromPennies($details->getValue()));
                $payment->setResult($details->getStatus());
                $payment->setMessage($details->getResponseMessage());
                $payment->setInfo($details->getResponseAdvancedInfo());
                $payment->setResponseCode($details->getResponseCode());
                $payment->setRiskScore($details->getRiskCheck());
                try {
                    $this->setCommission($payment, true);
                } catch (CommissionException $e) {
                    /**
                     * The one place that uses this exception at the moment does not require this method
                     * to return anything. So, we will re throw the error later, that way this will
                     * not interfere with anywhere else that uses this method, but give us there result
                     * that we want where we want it.
                     */
                    $throwLater = true;
                    $thingToThrow = $e;
                }
                $this->dm->flush(null, array('w' => 'majority', 'j' => true));
            }

            if (!$details || !CheckoutPayment::isSuccessfulResult($details->getStatus(), true)) {
                /**
                 * If the payment was not authorized, we will need to unset the
                 * previousChargeId so that on the next successful payment the
                 * new chargeId is set as previousChargeId for future payments.
                 */
                $paymentMethod->setPreviousChargeId('none');
                $this->dm->flush();
                throw new PaymentDeclinedException($details->getResponseMessage());
            }

            if ($details) {
                $card = $details->getCard();
                if ($card) {
                    $this->setCardToken($policy, $card);
                }
            }

            if ($amount !== null) {
                $capture = new ChargeCapture();
                $capture->setChargeId($details->getId());
                /**
                 * This is updating the card details, so we want to start a new chain of
                 * transactions using this chargeId for the new previousChargeId, so
                 * at this point we do not care if they have a previousChargeId set or
                 * not, we just want to set it to the new one anyway.
                 */
                $paymentMethod->setPreviousChargeId($details->getId());
                $details = $service->CaptureCardCharge($capture);
                $this->logger->info(sprintf('Update Payment Method Charge Resp: %s', json_encode($details)));

                /** @var ScheduledPaymentRepository */
                $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
                if ($details && $payment) {
                    $payment->setReceipt($details->getId());
                    $payment->setAmount($this->convertFromPennies($details->getValue()));
                    $payment->setResult($details->getStatus());
                    $payment->setMessage($details->getResponseMessage());
                    $payment->setInfo($details->getResponseAdvancedInfo());
                    $payment->setResponseCode($details->getResponseCode());
                    $payment->setRiskScore($details->getRiskCheck());
                    // Make sure upcoming rescheduled scheduled payments are now cancelled.
                    $rescheduledPayments = $scheduledPaymentRepo->findRescheduled($policy);
                    foreach ($rescheduledPayments as $rescheduled) {
                        if ($payment->getAmount() > 0) {
                            $rescheduled->cancel('Cancelled rescheduled payment as web payment made');
                        }
                    }
                    $this->dm->flush(null, array('w' => 'majority', 'j' => true));
                }
                /**
                 * Finally, as the transaction was not a 0 amount, we will need to ensure that it is paid to date
                 *  and if the policy is set as unpaid, it is now set to active.
                 */
                if ($policy->isPolicyPaidToDate()) {
                    $policy->setPolicyStatusActiveIfUnpaid();
                    $this->dm->flush();
                    if ($policy->getOutstandingPremium() <= 0) {
                        $futureSchedule = $scheduledPaymentRepo->getStillScheduled($policy);
                        /** @var ScheduledPayment $scheduledPayment */
                        foreach ($futureSchedule as $scheduledPayment) {
                            $scheduledPayment->cancel('Cancelling old scheduled as whole premium paid');
                        }
                        $this->dm->flush();
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending test payment. Msg: %s', $e->getMessage()),
                ['exception' => $e]
            );

            throw $e;
        }

        if ($details) {
            $card = $details->getCard();
            if ($card) {
                $this->setCardToken($policy, $card);
            }
        }

        // if a multipay user runs a payment direct on the policy, assume they want to remove multipay
        if ($policy && $policy->isDifferentPayer()) {
            // don't use $user as not validated that policy belongs to user
            $policy->setPayer($policy->getUser());
        }

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        if ($throwLater) {
            throw $thingToThrow;
        }
        return $details;
    }

    private function setCardToken(Policy $policy, Card $card)
    {
        /** @var CheckoutPaymentMethod $checkoutPaymentMethod */
        $checkoutPaymentMethod = $policy->getCheckoutPaymentMethod();
        if (!$checkoutPaymentMethod) {
            $checkoutPaymentMethod = new CheckoutPaymentMethod();
            $policy->setPaymentMethod($checkoutPaymentMethod);
        }

        /**
         * The original token migration used fake emails and there are ways that
         * a payment can be made using the customers email address rather than
         * the existing customerId that we have in the db.
         * If the returned customerId differs from what we have, then we will
         * update it at the same time as setting the new token.
         */
        if (!$checkoutPaymentMethod->getCustomerId() ||
            $checkoutPaymentMethod->getCustomerId() !== $card->getCustomerId()
        ) {
            $checkoutPaymentMethod->setCustomerId($card->getCustomerId());
        }

        $tokens = $checkoutPaymentMethod->getCardTokens();
        if (!isset($tokens[$card->getId()])) {
            $cardDetails = [
                'cardLastFour' => $card->getLast4(),
                'endDate' => sprintf('%02d%02d', $card->getExpiryMonth(), mb_substr($card->getExpiryYear(), 2, 2)),
                'cardType' => $card->getPaymentMethod(),
                'fingerprint' => $card->getFingerprint(),
                'authCode' => $card->getAuthCode(),
                'cvvCheck' => $card->getCvvCheck(),
                'avsCheck' => $card->getAvsCheck(),
            ];
            $checkoutPaymentMethod->addCardToken($card->getId(), json_encode($cardDetails));
        } else {
            $checkoutPaymentMethod->setCardToken($card->getId());
        }
    }

    /**
     * @param Policy    $policy
     * @param string    $chargeId
     * @param string    $source
     * @param \DateTime $date
     */
    public function validateCharge(Policy $policy, $chargeId, $source, \DateTime $date = null)
    {
        $transactionDetails = $this->getCharge($chargeId);
        $repo = $this->dm->getRepository(CheckoutPayment::class);
        $exists = $repo->findOneBy(['receipt' => $transactionDetails->getId()]);
        if ($exists) {
            throw new ProcessedException(sprintf(
                "Charge %s has already been used to pay for a policy",
                $transactionDetails->getId()
            ));
        }

        // webpayment will already have a payment record

        // Try to find payment via policy object, so that there isn't any inconsistencies
        // Uncertain if this is doing anything productive or not, but there was an error
        // that seems like it could only be causes by loading an unflush db record - ch4972
        /** @var CheckoutPayment $payment */
        $payment = null;
        foreach ($policy->getPayments() as $payment) {
            if ($payment->getId() == $transactionDetails->getTrackId()) {
                break;
            }

            /** @var CheckoutPayment $payment */
            $payment = null;
        }
        // Fallback to db query if unable to find
        if (!$payment) {
            /** @var CheckoutPayment $payment */
            $payment = $repo->find($transactionDetails->getTrackId());
        }

        $transactionAmount = $this->convertFromPennies($transactionDetails->getValue());
        if (!$payment) {
            $payment = new CheckoutPayment();
            $payment->setReference($transactionDetails->getTrackId());
            $payment->setAmount($transactionAmount);
            $payment->setUser($policy->getUser());
            $policy->addPayment($payment);
            $this->dm->persist($payment);
            //\Doctrine\Common\Util\Debug::dump($payment);
        } else {
            if (!$this->areEqualToTwoDp($payment->getAmount(), $transactionAmount)) {
                $this->logger->error(sprintf(
                    'Payment %s Expected Matching Payment Amount %f',
                    $payment->getId(),
                    $transactionAmount
                ));
            }
        }

        $payment->setReceipt($transactionDetails->getId());
        $payment->setResult($transactionDetails->getStatus());
        $payment->setMessage($transactionDetails->getResponseMessage());
        $payment->setInfo($transactionDetails->getResponseAdvancedInfo());
        $payment->setResponseCode($transactionDetails->getResponseCode());
        $payment->setRiskScore($transactionDetails->getRiskCheck());
        $payment->setSource($source);

        if ($date) {
            $payment->setDate($date);
        }

        /** @var Card $card */
        $card = $transactionDetails->getCard();

        if ($card) {
            $this->setCardToken($policy, $card);
            $payment->setCardLastFour($card->getLast4());
        }

        /** @var CheckoutPaymentMethod $checkoutPaymentMethod */
        $checkoutPaymentMethod = $policy->getCheckoutPaymentMethod();
        if ($checkoutPaymentMethod && !$payment->getDetails()) {
            $payment->setDetails($checkoutPaymentMethod->__toString());
        }
        //\Doctrine\Common\Util\Debug::dump($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        $metadata = $transactionDetails->getMetadata();
        if (!$metadata || !isset($metadata["policy_id"])) {
            $this->logger->warning(sprintf('Unable to find policy id metadata for payment id %s', $payment->getId()));
        } elseif ($metadata["policy_id"] != $policy->getId()) {
            $this->logger->error(sprintf(
                'Payment id %s metadata [%s] does not match policy id %s',
                $payment->getId(),
                json_encode($metadata),
                $policy->getId()
            ));
        }

        // Ensure the correct amount is paid
        $this->validatePaymentAmount($payment);

        if ($payment->getResult() != CheckoutPayment::RESULT_CAPTURED &&
            $payment->getResult() != CheckoutPayment::RESULT_AUTHORIZED
        ) {
            // We've recorded the payment - can return error now
            throw new PaymentDeclinedException();
        }

        if (!$payment->getPolicy()) {
            $payment->setPolicy($policy);
        }

        try {
            $this->setCommission($payment, true);
        } catch (CommissionException $e) {
            /**
             * At this point the commission has been set but is likely incorrect.
             * We will log the error so that it can be manually dealt with,
             * but for now we still want to continue with the rest of the code.
             */
            $this->logger->error($e->getMessage());
        }

        return $payment;
    }

    protected function validatePaymentAmount(CheckoutPayment $payment)
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
        try {
            $scheduledPayment->validateRunable($prefix, $date);
        } catch (ScheduledPaymentException $e) {
            /**
             * This should never be thrown as the only place that calls this that is not
             * a test file has the same check before it calls this method.
             * Nonetheless I have checked for the exception here because it would be amiss
             * not to be conservative in my code so that even code that shouldn't ever be
             * hit is complete, on the off chance that it could be hit.
             * For now we will rethrow the exception too so that the calling method can
             * decide what to do with the exception.
             */
            $this->logger->error($e->getMessage());
            throw $e;
        }

        $payment = null;
        $policy = $scheduledPayment->getPolicy();
        $paymentMethod = $policy->getCheckoutPaymentMethod();
        if (!$paymentMethod || !$paymentMethod instanceof CheckoutPaymentMethod) {
            throw new InvalidPaymentMethodException(sprintf(
                'Payment method not valid for scheduled payment %s',
                $scheduledPayment->getId()
            ));
        }
        try {
            $payment = $this->tokenPay(
                $policy,
                $scheduledPayment->getAmount(),
                $scheduledPayment->getNotes() ?: $scheduledPayment->getType(),
                $abortOnMultipleSameDayPayment,
                $date,
                true
            );
        } catch (SameDayPaymentException $e) {
            $this->dm->flush(null, array('w' => 'majority', 'j' => true));

            throw $e;
        } catch (\Exception $e) {
            // TODO: Nicer handling if Checkout has an issue
            $this->logger->error(sprintf(
                'Error running scheduled payment %s. Ex: %s',
                $scheduledPayment->getId(),
                $e->getMessage()
            ));
        }

        if (!$payment) {
            $payment = new CheckoutPayment();
            $payment->setAmount(0);
            try {
                $payment->setResult(CheckoutPayment::RESULT_SKIPPED);
            } catch (\Exception $e) {
                /**
                 * This Exception should not be thrown in this instance, as we know that we are
                 * setting a status that is valid. So if we do, it must mean that the statuses
                 * have changed and therefore this method needs changing.
                 */
                $this->logger->error(
                    "Tried to set a CheckoutPayment status that does not exist 'CheckoutPayment::RESULT_SKIPPED"
                );
            }
            if ($policy->getCheckoutPaymentMethod()) {
                $payment->setDetails($policy->getCheckoutPaymentMethod()->__toString());
            }
            try {
                $policy->addPayment($payment);
            } catch (DuplicatePaymentException $e) {
                /**
                 * This exception means that we have already added the payment to the DB.
                 * I cannot guarantee that that means that every step has been done at
                 * this point so we may as well continue with the rest of the code.
                 * But we should log that it did so that we can monitor it anyway.
                 */
                $this->logger->notice(sprintf(
                    "Payment %s has already been added to Policy %s",
                    $payment->getId(),
                    $policy->getId()
                ));
            }
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

    public function processScheduledPaymentResult(
        ScheduledPayment $scheduledPayment,
        CheckoutPayment $payment = null,
        \DateTime $date = null
    ) {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $policy = $scheduledPayment->getPolicy();
        if ($payment) {
            $scheduledPayment->setPayment($payment);
        }
        if ($payment && $payment->isSuccess()) {
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

        if (!$policy->getCheckoutPaymentMethod() ||
            !$policy->getCheckoutPaymentMethod()->isCardExpired($nextMonth)) {
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

    public function runTokenPayment(Policy $policy, $amount, $paymentRef, $policyId, $recurring = false)
    {
        /** @var CheckoutPaymentMethod $paymentMethod */
        $paymentMethod = $policy->getCheckoutPaymentMethod();
        if (!$paymentMethod) {
            throw new \Exception(sprintf(
                'Unknown payment method for policy %s user %s',
                $policy->getId(),
                $policy->getPayerOrUser()->getId()
            ));
        }

        $user = $policy->getUser();

        $chargeService = $this->client->chargeService();
        $chargeCreate = new CardIdChargeCreate();
        if ($paymentMethod->hasPreviousChargeId()) {
            $chargeCreate->setPreviousChargeId($paymentMethod->getPreviousChargeId());
        }
        $chargeCreate->setBillingDetails($this->getCheckoutAddress($user));

        // Can only use 1
        if ($paymentMethod->getCustomerId()) {
            $chargeCreate->setCustomerId($paymentMethod->getCustomerId());
        } else {
            $chargeCreate->setEmail($user->getEmail());
        }
        $chargeCreate->setAutoCapTime(0);
        $chargeCreate->setAutoCapture('N');
        $chargeCreate->setValue($this->convertToPennies($amount));
        $chargeCreate->setCurrency('GBP');
        $chargeCreate->setTrackId($paymentRef);
        $chargeCreate->setMetadata(['policy_id' => $policyId]);
        $chargeCreate->setCardId($paymentMethod->getCardToken());
        if ($recurring) {
            $chargeCreate->setTransactionIndicator(2);
        }

        $chargeResponse = $chargeService->chargeWithCardId($chargeCreate);

        $capture = new ChargeCapture();
        $capture->setChargeId($chargeResponse->getId());
        $capture->setValue($this->convertToPennies($amount));
        if (!$paymentMethod->hasPreviousChargeId()) {
            $paymentMethod->setPreviousChargeId($capture->getChargeId());
            $this->dm->flush();
        }
        /** @var \com\checkout\ApiServices\Charges\ResponseModels\Charge $details */
        $chargeResponse = $chargeService->CaptureCardCharge($capture);

        return $chargeResponse;
    }

    protected function tokenPay(
        Policy $policy,
        $amount = null,
        $notes = null,
        $abortOnMultipleSameDayPayment = true,
        \DateTime $date = null,
        $recurring = false
    ) {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        foreach ($policy->getAllPayments() as $payment) {
            $diff = $date->diff($payment->getDate());
            if ($payment instanceof CheckoutPayment && $payment->getAmount() > 0 &&
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

        $payment = new CheckoutPayment();
        $payment->setAmount($amount);
        $payment->setNotes($notes);
        $payment->setUser($policy->getUser());
        $payment->setSource(Payment::SOURCE_TOKEN);
        if ($policy->getCheckoutPaymentMethod()) {
            $payment->setDetails($policy->getCheckoutPaymentMethod()->__toString());
        }
        try {
            $policy->addPayment($payment);
        } catch (DuplicatePaymentException $e) {
            /**
             * This exception means that we have already added the payment to the DB.
             * I cannot guarantee that that means that every step has been done at
             * this point so we may as well continue with the rest of the code.
             * But we should log that it did so that we can monitor it anyway.
             */
            $this->logger->notice(sprintf(
                "Payment %s has already been added to Policy %s",
                $payment->getId(),
                $policy->getId()
            ));
        }
        $this->dm->persist($payment);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        /**
         * The isValid check only checks for expired cards,
         * so seeing as we are now going to be using the account updaters
         * to automatically update expired cards, we don't need to check this anymore.
         */
        if (($policy->hasPaymentMethod() && $recurring) || $policy->hasPolicyOrUserValidPaymentMethod()) {
            try {
                $tokenPaymentDetails = $this->runTokenPayment(
                    $policy,
                    $amount,
                    $payment->getId(),
                    $policy->getId(),
                    $recurring
                );
            } catch (\Exception $e) {
                /**
                 * At this point we cannot continue with the payment, we will log the exception
                 * and rethrow the error so the calling method can decide how to continue.
                 */
                $this->logger->error($e->getMessage());
                throw $e;
            }

            $payment->setReceipt($tokenPaymentDetails->getId());
            $payment->setAmount($this->convertFromPennies($tokenPaymentDetails->getValue()));
            $payment->setResult($tokenPaymentDetails->getStatus());
            $payment->setMessage($tokenPaymentDetails->getResponseMessage());
            $payment->setInfo($tokenPaymentDetails->getResponseAdvancedInfo());
            $payment->setResponseCode($tokenPaymentDetails->getResponseCode());
            $payment->setRiskScore($tokenPaymentDetails->getRiskCheck());
        } else {
            $this->logger->info(sprintf(
                'User %s does not have a valid payment method (Policy %s)',
                $user->getId(),
                $policy->getId()
            ));
            $payment->setResult(CheckoutPayment::RESULT_SKIPPED);
        }

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        // Ensure the correct amount is paid
        $this->validatePaymentAmount($payment);

        // TODO: Validate receipt does not set commission on failed payments, but token does
        // make consistent
        try {
            $this->setCommission($payment, true);
        } catch (CommissionException $e) {
            /**
             * At this point the commission has been set but is likely incorrect.
             * We will log the error so that it can be manually dealt with,
             * but for now we still want to continue with the rest of the code.
             */
            $this->logger->error($e->getMessage());
        }

        $this->triggerPaymentEvent($payment);

        return $payment;
    }

    public function setCommission($payment, $allowFraction = false)
    {
        try {
            $payment->setCommission($allowFraction);
        } catch (CommissionException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * Refund a payment
     *
     * @param CheckoutPayment $payment
     * @param float           $amount         Amount to refund (or null for entire initial amount)
     * @param float           $totalCommision Total commission amount to refund (or null for entire amount from payment)
     * @param string          $notes
     * @param string          $source
     *
     * @return CheckoutPayment
     */
    public function refund(
        CheckoutPayment $payment,
        $amount = null,
        $totalCommision = null,
        $notes = null,
        $source = null
    ) {
        if (!$amount) {
            $amount = $payment->getAmount();
        }
        if (!$totalCommision) {
            $totalCommision = $payment->getTotalCommission();
        }
        $policy = $payment->getPolicy();

        // Refund is a negative payment
        $refund = new CheckoutPayment();
        $refund->setAmount(0 - $amount);
        $refund->setNotes($notes);
        $refund->setSource($source);
        if ($policy->getCheckoutPaymentMethod()) {
            $payment->setDetails($policy->getCheckoutPaymentMethod()->__toString());
        }
        $policy->addPayment($refund);
        $this->dm->persist($refund);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        $chargeService = $this->client->chargeService();
        $chargeRefund = new ChargeRefund();
        $chargeRefund->setChargeId($payment->getReceipt());
        $chargeRefund->setValue($this->convertToPennies($amount));
        try {
            $refundDetails = $chargeService->refundCardChargeRequest($chargeRefund);
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Error running refund %s (%0.2f >? %0.2f)',
                $refund->getId(),
                $this->toTwoDp(abs($refund->getAmount())),
                $payment->getAmount()
            ), ['exception' => $e]);

            throw $e;
        }

        $receiptId = $refundDetails->getId();
        $repo = $this->dm->getRepository(Payment::class);
        $payment = $repo->findOneBy(['receiptId' => $receiptId]);
        if ($payment) {
            $receiptId = sprintf('R-%s', $receiptId);
        }

        $refund->setReceipt($receiptId);
        $refund->setResult($refundDetails->getStatus());
        $refund->setMessage($refundDetails->getResponseMessage());
        $refund->setInfo($refundDetails->getResponseAdvancedInfo());
        $refund->setResponseCode($refundDetails->getResponseCode());
        $refund->setRiskScore($refundDetails->getRiskCheck());

        $refundAmount = $this->convertFromPennies($refundDetails->getValue());
        $refund->setAmount(0 - $refundAmount);
        //$refund->setReference($refundModelDetails["yourPaymentReference"]);

        $refund->setRefundTotalCommission($totalCommision);

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return $refund;
    }

    public function processCsv(CheckoutFile $checkoutFile)
    {
        $filename = $checkoutFile->getFile();
        $header = null;
        $lines = array();
        $dailyTransaction = array();

        $payments = 0;
        $numPayments = 0;
        $refunds = 0;
        $numRefunds = 0;
        $declined = 0;
        $numDeclined = 0;
        $total = 0;
        $maxDate = null;
        if (($handle = fopen($filename, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000)) !== false) {
                // Remove empty columns appearing in header.
                $row = array_slice($row, 0, 55);
                if (count($row) == 0) {
                    continue;
                } elseif (!$header) {
                    $header = $row;
                } else {
                    if (count($header) != count($row)) {
                        throw new \Exception(sprintf('%s != %s', json_encode($header), json_encode($row)));
                    }
                    $line = array_combine($header, $row);
                    $lines[] = $line;
                    $transactionDate = \DateTime::createFromFormat(
                        'Y-m-d H:i:s',
                        $line['Action Date Europe/London'],
                        SoSure::getSoSureTimezone()
                    );
                    if (!$transactionDate) {
                        throw new \Exception(sprintf('Unable to parse date %s', $line['Action Date Europe/London']));
                    }
                    $transactionDate = self::convertTimezone($transactionDate, new \DateTimeZone('UTC'));

                    if (!isset($dailyTransaction[$transactionDate->format('Ymd')])) {
                        $dailyTransaction[$transactionDate->format('Ymd')] = 0;
                    }


                    if ($line['Response Code'] == CheckoutPayment::RESPONSE_CODE_SUCCESS) {
                        // "Capture" and not CheckoutPayment::RESULT_CAPTURED :(
                        if ($line['Action Type'] == "Capture") {
                            $total += $line['Amount'];
                            $payments += $line['Amount'];
                            $numPayments++;
                            $dailyTransaction[$transactionDate->format('Ymd')] += $line['Amount'];
                        } elseif ($line['Action Type'] == "Refund") {
                            $total -= $line['Amount'];
                            $refunds += $line['Amount'];
                            $numRefunds++;
                            $dailyTransaction[$transactionDate->format('Ymd')] -= $line['Amount'];
                        }
                    } elseif ($line['Response Code'] != CheckoutPayment::RESPONSE_CODE_SUCCESS) {
                        $declined += $line['Amount'];
                        $numDeclined++;
                    } else {
                        throw new \Exception(sprintf('Unknown Response Result: %s', $line['Response Code']));
                    }

                    if ($maxDate && $maxDate->format('m') != $transactionDate->format('m')) {
                        throw new \Exception('Export should only be for the same calendar month');
                    }

                    if (!$maxDate || $maxDate > $transactionDate) {
                        $maxDate = clone $transactionDate;
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
            'dailyTransaction' => $dailyTransaction,
            'data' => $lines,
        ];

        $checkoutFile->addMetadata('total', $data['total']);
        $checkoutFile->addMetadata('payments', $data['payments']);
        $checkoutFile->addMetadata('numPayments', $data['numPayments']);
        $checkoutFile->addMetadata('refunds', $data['refunds']);
        $checkoutFile->addMetadata('numRefunds', $data['numRefunds']);
        $checkoutFile->addMetadata('declined', $data['declined']);
        $checkoutFile->addMetadata('numDeclined', $data['numDeclined']);
        $checkoutFile->setDailyTransaction($data['dailyTransaction']);
        $checkoutFile->setDate($data['date']);

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
