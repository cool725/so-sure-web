<?php

namespace AppBundle\Service;

use AppBundle\Classes\NoOp;
use AppBundle\Classes\SoSure;
use AppBundle\Classes\Salva;
use AppBundle\Classes\Helvetia;
use AppBundle\Document\Charge;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\CheckoutFile;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Exception\CommissionException;
use AppBundle\Exception\DuplicatePaymentException;
use AppBundle\Exception\InvalidPaymentMethodException;
use AppBundle\Exception\ScheduledPaymentException;
use AppBundle\Exception\IncorrectPriceException;
use AppBundle\Repository\CheckoutPaymentRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use Checkout\CheckoutApi;
use Checkout\CheckoutApiException;
use Checkout\CheckoutArgumentException;
use Checkout\CheckoutAuthorizationException;
use Checkout\CheckoutDefaultSdk;
use Checkout\Common\Country;
use Checkout\Common\Currency;
use Checkout\Common\CustomerRequest;
use Checkout\Environment;
use Checkout\Payments\PaymentRequest;
use Checkout\Payments\ThreeDsRequest;
use Checkout\Payments\RiskRequest;
use Checkout\Payments\Source\RequestCardSource;
use Checkout\Payments\Source\RequestTokenSource;
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
use com\checkout\ApiServices\Tokens\ResponseModels\CardToken;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

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

/**
 * Handles payments through checkout.com.
 */
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

    /** @var PriceService */
    protected $priceService;

    /** @var string */
    protected $salvaApiSecret;

    /** @var string */
    protected $salvaApiPublic;

    /** @var string */
    protected $helvetiaApiSecret;

    /** @var string */
    protected $helvetiaApiPublic;

    /** @var boolean */
    protected $production;

    /** @var RouterService */
    protected $routerService;

    /**
     * Injects the service's dependencies.
     * @param DocumentManager          $dm                is used for database access.
     * @param LoggerInterface          $logger            is used for logging.
     * @param PolicyService            $policyService     is used to update policies in response to payment.
     * @param MailerService            $mailer            is used to email users in response to payment status.
     * @param string                   $salvaApiSecret    is the private key for the old checkout channel.
     * @param string                   $salvaApiPublic    is the public key for the old checkout channel.
     * @param string                   $helvetiaApiSecret is the private key for the new checkout channel.
     * @param string                   $helvetiaApiPublic is the public key for the new checkout channel.
     * @param string                   $environment       is the environment that the service is in.
     * @param \Domnikl\Statsd\Client   $statsd            is used to record stats.
     * @param EventDispatcherInterface $dispatcher        is used to dispatch payment events events.
     * @param SmsService               $sms               is used to sms users in response to payment status.
     * @param FeatureService           $featureService    is used to check if features are enabled.
     * @param PriceService             $priceService      is used to verify paid sums.
     * @param RouterService            $routerService     is used to generate urls
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        PolicyService $policyService,
        MailerService $mailer,
        $salvaApiSecret,
        $salvaApiPublic,
        $helvetiaApiSecret,
        $helvetiaApiPublic,
        $environment,
        \Domnikl\Statsd\Client $statsd,
        EventDispatcherInterface $dispatcher,
        SmsService $sms,
        FeatureService $featureService,
        PriceService $priceService,
        RouterService $routerService
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
        $this->priceService = $priceService;
        $this->salvaApiSecret = $salvaApiSecret;
        $this->salvaApiPublic = $salvaApiPublic;
        $this->helvetiaApiSecret = $helvetiaApiSecret;
        $this->helvetiaApiPublic = $helvetiaApiPublic;
        $this->production = $environment === 'prod';
        $this->routerService = $routerService;
    }

    /**
     * Sets the service's event dispatcher.
     * @param EventDispatcherInterface $dispatcher is the dispatcher to set it to.
     */
    public function setDispatcher($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Gives the checkout client to the channel that should be used for payments regarding named underwriter.
     * @param string $underwriter is the name of the underwriter that we are getting the checkout client for.
     * @return ApiClient the client for the named underwriter. If none can be found then an illegal argument exception
     *                   will be thrown.
     */
    public function getClientForUnderwriter($underwriter)
    {
        $apiSecret = null;
        if ($underwriter === Salva::NAME) {
            $apiSecret = $this->salvaApiSecret;
        } elseif ($underwriter === Helvetia::NAME) {
            $apiSecret = $this->helvetiaApiSecret;
        }
        if ($apiSecret) {
            return new ApiClient($apiSecret, $this->production ? 'live' : 'sandbox', !$this->production);
        }
        throw new \InvalidArgumentException("{$underwriter} is not the name of a valid underwriter");
    }

    /**
     * Gives the checkout api that should be used for payments regarding named underwriter.
     * @param string $underwriter is the name of the underwriter that we are getting the checkout api for.
     * @return CheckoutApi the correct api if there is one. An illegal argument exception will be thrown if there is
     *                     not appropriate api.
     */
    public function getApiForUnderwriter($underwriter)
    {
        $apiSecret = null;
        $apiPublic = null;
        if ($underwriter === Salva::NAME) {
            $apiSecret = $this->salvaApiSecret;
            $apiPublic = $this->salvaApiPublic;
        } elseif ($underwriter === Helvetia::NAME) {
            $apiSecret = $this->helvetiaApiSecret;
            $apiPublic = $this->helvetiaApiPublic;
        }
        if ($apiSecret && $apiPublic) {
            $builder = CheckoutDefaultSdk::staticKeys();
            $builder->setPublicKey($apiPublic); // optional, only required for operations related with tokens
            $builder->setSecretKey($apiSecret);
            $builder->setEnvironment($this->production ? Environment::production() : Environment::sandbox());
            return $builder->build();
        }
        throw new \InvalidArgumentException("{$underwriter} is not the name of a valid underwriter");
    }

    /**
     * Gives the checkout client that should be used for payments regarding a given policy. If the policy has no
     * appropriate client an exception will be thrown but that shouldn't be possible.
     * @param Policy $policy is the policy that we are going to work on with the client.
     * @return ApiClient the appropriate client.
     */
    public function getClientForPolicy(Policy $policy)
    {
        return $this->getClientForUnderwriter($policy->getUnderwriterName());
    }
    

    /**
     * Gives the checkout api that should be used for payments regarding a given policy. If the policy has no
     * appropriate api an exception will be thrown but that shouldn't be possible.
     * @param Policy $policy is the policy that we are going to work on with the API.
     * @return CheckoutApi the appropriate api.
     */
    public function getApiForPolicy(Policy $policy)
    {
        return $this->getApiForUnderwriter($policy->getUnderwriterName());
    }

    /**
     * Gets the details of a transaction from checkout.
     * @param ApiClient $client   the client to use to get this transaction (which is the client that the transaction
     *                            was made with).
     * @param string    $chargeId the id of the charge to get.
     * @return \com\checkout\ApiServices\Charges\ResponseModels\Charge
     */
    public function getTransaction($client, $chargeId)
    {
        $charge = $client->chargeService();
        /** @var \com\checkout\ApiServices\Charges\ResponseModels\Charge $details */
        $details = $charge->getCharge($chargeId);
        return $details;
    }

    public function getTransactions($pageSize, $logMissing = true)
    {
        $policies = [];
        $repo = $this->dm->getRepository(CheckoutPayment::class);

        $filter = new TransactionFilter();
        $filter->setPageSize($pageSize);
        //$filter->setSearch()

        $client = $this->getClientForUnderwriter(Helvetia::NAME);
        $transactions = $client->reportingService()->queryTransaction($filter);
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
            $details = $this->getTransaction($client, $transaction->getId());
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

    /**
     * Accepts a user initiated payment.
     * @param Policy         $policy      is the paid for policy.
     * @param string         $token       is the checkout token for the payment.
     * @param number         $amount      is the amount the payment is supposed to be.
     * @param string         $source      is the kind of payment.
     * @param \DateTime|null $date        is the date and time of the payment.
     * @param IdentityLog    $identityLog does something.
     * @param boolean        $threeDS     whether to use 3ds.
     * @return string|null a redirect url if the payment requires 3ds interaction and null otherwise.
     */
    public function pay(
        Policy $policy,
        $token,
        $amount,
        $source,
        \DateTime $date = null,
        IdentityLog $identityLog = null,
        $threeDS = false
    ) {

        $details = $this->capturePaymentMethod($policy, $token, $amount, $threeDS);
        if ($details['status'] === 'Pending') {
            $this->logger->info(sprintf('3DS redirected : %s', json_encode($details)));
            return $details['_links']['redirect']['href'];
        } else {
            $this->add($policy, $details['id'], $source, $date, $identityLog);
            return null;
        }
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
        } elseif ($policy->getStatus() == PhonePolicy::STATUS_PICSURE_REQUIRED) {
            // This shouldn't happen but if it does we must credit it anyway. It should not cause any harm.
            $this->logger->error(sprintf(
                "Picsure-required policy made non-token payment.\npolicyId: %s",
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
            try {
                if ($policy instanceof PhonePolicy) {
                    $this->priceService->phonePolicyDeterminePremium($policy, $payment->getAmount(), new \DateTime());
                }
                try {
                    $this->setCommission($payment, true);
                } catch (CommissionException $e) {
                    $this->logger->error($e->getMessage());
                }
                $this->policyService->create($policy, $date, true, null, $identityLog);
                $this->dm->flush();
            } catch (IncorrectPriceException $e) {
                $this->logger->error(sprintf(
                    "Policy '%s' tried to purchase with invalid price %f",
                    $policy->getId(),
                    $payment->getAmount()
                ));
                return true;
            }
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

    public function testPay(Policy $policy, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId = null)
    {
        $charge = $this->testPayDetails($policy, $ref, $amount, $cardNumber, $expiryDate, $cv2, $policyId);
        if (!$charge) {
            return null;
        }
        return $charge->getId();
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

            $client = $this->getClientForPolicy($policy);
            $service = $client->chargeService();
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
                    $this->setCardToken($policy, $card->getCustomerId(), $card);
                }
            }

            if (!$paymentMethod->hasPreviousChargeId()) {
                $paymentMethod->setPreviousChargeId($details->getId());
                $this->dm->flush();
            }

            $service = $client->chargeService();
            $details = $service->CaptureCardCharge($capture);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending test payment. Msg: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }

        return $details;
    }

    public function getCharge(
        $api,
        $chargeId,
        $enforceFullAmount = true,
        $enforceDate = true,
        \DateTime $date = null
    ): array {
        $paymentsClient = $api->getPaymentsClient();
        $details = $paymentsClient->getPaymentActions($chargeId);
        $hasRefund = false;
        $refundedAmount = 0;
        $amount = 0;
        foreach ($details['items'] as $action) {
            /** @var \com\checkout\ApiServices\SharedModels\Charge $charge */
            if ($action['type'] == CheckoutPayment::TYPE_REFUND) {
                $hasRefund = true;
                $refundedAmount += $action['amount'];
            } elseif ($action['type'] == CheckoutPayment::TYPE_CAPTURED) {
                $amount += $action['amount'];
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
        $transactionDetails = $paymentsClient->getPaymentDetails($chargeId);
        $created = new \DateTime($transactionDetails['requested_on']);
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

    /**
     * @param Policy $policy
     * @param string $token
     * @param mixed  $amount
     */
    public function capturePaymentMethod(
        Policy $policy,
        $token,
        $amount = null,
        $threeDS = false
    ): array {
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
            $api = $this->getApiForPolicy($policy);
            $source = new RequestTokenSource();
            $source->token = $token;

            // Prepare the payment parameters
            $payment = new PaymentRequest();
            $payment->source = $source;
            $payment->currency = Currency::$GBP;
            if ($amount) {
                $payment->amount = $this->convertToPennies($amount);
            }
            $customer = new CustomerRequest();
            $customer->email = $user->getEmail();
            $customer->name = $user->getName();
            $payment->customer = $customer;
            $payment->capture = true;
            $payment->reference = 'CKO-' . $policy->getPolicyNumber() . '-001';
            if ($threeDS) {
                $payment->three_ds = new ThreeDsRequest();
                $payment->success_url = $this->routerService
                    ->generateUrl('confirm_3ds', ['id' => $policy->getId()]);
            }
            $payment->risk = new RiskRequest();
            $payment->risk->enabled = true;
            $payment->failure_url = $this->routerService
                ->generateUrl('purchase_step_payment_id', ['id' => $policy->getId()]);

            if ($paymentMethod->hasPreviousChargeId()) {
                $payment->previous_payment_id = ($paymentMethod->getPreviousChargeId());
            }

            // Send the request and retrieve the response
            $details = $api->getPaymentsClient()->requestPayment($payment);

            if (!$paymentMethod->hasPreviousChargeId()) {
                $paymentMethod->setPreviousChargeId($details['id']);
            }

            $this->logger->info(sprintf('3DS Payment Details: %s', json_encode($details)));

            if (!$details || !array_key_exists('status', $details) || !$details['status']) {
                /**
                 * If the payment was not authorized, we will need to unset the
                 * previousChargeId so that on the next successful payment the
                 * new chargeId is set as previousChargeId for future payments.
                 */
                $paymentMethod->setPreviousChargeId('none');
                $this->dm->flush();
                throw new PaymentDeclinedException($details['response_summary']);
            }
            // @codingStandardsIgnoreEnd

            // if ($details) {
            //     $card = $details->getCard();
            //     if ($card) {
            //         $this->setCardToken($policy, $card);
            //     }
            // }
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending test payment. Msg: %s', $e->getMessage()),
                ['exception' => $e]
            );

            throw $e;
        }
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));
        return $details;
    }

    /**
     * @param Policy $policy
     * @param string $sessionToken
     */
    public function confirm3DSPayment(
        Policy $policy,
        $sessionToken
    ) {
        $user = $policy->getUser();
        $paymentMethod = $policy->getPaymentMethod();
        $paymentRepo = $this->dm->getRepository(CheckoutPayment::class);
        try {
            $api = $this->getApiForPolicy($policy);
            $details = $api->getPaymentsClient()->getPaymentDetails($sessionToken);
            if ($details['approved']) {
                $this->add($policy, $details['id'], Payment::SOURCE_WEB);
                if (!$paymentMethod->hasPreviousChargeId()) {
                    $paymentMethod->setPreviousChargeId($details['id']);
                }
                $card = $details['source'];
                if ($card) {
                    $this->setCardToken($policy, $details['customer']['id'], $card);
                }
            } else {
                $paymentMethod->setPreviousChargeId('none');
                $this->dm->flush();
                throw new PaymentDeclinedException($details->getResponseMessage());
            }
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending test payment. Msg: %s', $e->getMessage()),
                ['exception' => $e]
            );
            throw $e;
        }
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return $details;

    }

    /**
     * Stores new card details and starts a new chain of payments.
     * @param Policy           $policy             is the policy to whom the new details belong.
     * @param string           $token              is the checkout token with which we can make the request to
     *                                             checkout.
     * @param float|null       $amount             is an optional amount of money to charge in the same request.
     * @param BacsPayment|null $coveredBacsPayment is an optional bacs payment to say that the created payment is
     *                                             covering for while it pends.
     */
    public function updatePaymentMethod(Policy $policy, $token, $amount = null, $coveredBacsPayment = null)
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
            $client = $this->getClientForPolicy($policy);
            $service = $client->chargeService();

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
                if ($coveredBacsPayment) {
                    $coveredBacsPayment->setCoveredBy($payment);
                    $coveredBacsPayment->setCoveringPaymentRefunded(false);
                    $payment->setCovering($coveredBacsPayment);
                }
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
                    $this->setCardToken($policy, $card->getCustomerId(), $card);
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
                $this->setCardToken($policy, $card->getCustomerId(), $card);
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

    /**
     * @param Policy    $policy
     * @param string    $chargeId
     * @param string    $source
     * @param \DateTime $date
     * @return CheckoutPayment
     */
    public function validateCharge(Policy $policy, $chargeId, $source, \DateTime $date = null): CheckoutPayment
    {
        $transactionDetails = $this->getCharge($this->getApiForPolicy($policy), $chargeId);
        $repo = $this->dm->getRepository(CheckoutPayment::class);
        $exists = $repo->findOneBy(['receipt' => $chargeId]);
        if ($exists) {
            throw new ProcessedException(sprintf(
                "Charge %s has already been used to pay for a policy",
                $chargeId
            ));
        }
        /** @var CheckoutPayment $payment */
        $payment = $repo->find($transactionDetails['reference']);
        $transactionAmount = $this->convertFromPennies($transactionDetails['amount']);
        if (!$payment) {
            $payment = new CheckoutPayment();
            $payment->setReference($transactionDetails['reference']);
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
        $payment->setReceipt($transactionDetails['id']);
        $payment->setResult($transactionDetails['status']);
        $payment->setMessage($transactionDetails['status']);
        $payment->setRiskScore($transactionDetails['risk']['flagged']);
        $payment->setSource($source);
        if ($date) {
            $payment->setDate($date);
        }
        /** @var Card $card */
        $sourceDetails = $transactionDetails['source'];
        if ($sourceDetails['type'] == 'card') {
            $this->setCardToken($policy, $transactionDetails['customer']['id'], $sourceDetails);
            $payment->setCardLastFour($sourceDetails['last4']);
        }
        /** @var CheckoutPaymentMethod $checkoutPaymentMethod */
        $checkoutPaymentMethod = $policy->getCheckoutPaymentMethod();
        if ($checkoutPaymentMethod && !$payment->getDetails()) {
            $payment->setDetails($checkoutPaymentMethod->__toString());
        }
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));
        $metadata = [];
        if (array_key_exists('metadata', $transactionDetails)) {
            $metadata = $transactionDetails['metadata'];
        }
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
        if (!$payment->getPolicy()) {
            $payment->setPolicy($policy);
        }
        // Ensure the correct amount is paid
        $this->validatePaymentAmount($payment);
        if ($payment->getResult() != CheckoutPayment::RESULT_CAPTURED) {
            // We've recorded the payment - can return error now
            throw new PaymentDeclinedException();
        }
        if ($policy->getPremium()) {
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
        }
        return $payment;
    }

    /**
     * Run via scheduledPaymentService
     */
    public function scheduledPayment(
        ScheduledPayment $scheduledPayment,
        \DateTime $date = null,
        $abortOnMultipleSameDayPayment = true
    ): ScheduledPayment {
        try {
            $scheduledPayment->validateRunable($date);
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
        $client = $this->getClientForPolicy($policy);
        $chargeService = $client->chargeService();
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

    /**
     * Refund a payment.
     * @param CheckoutPayment $payment
     * @param float           $amount                Amount to refund (or null for entire initial amount)
     * @param float           $coverholderCommission total commission amount to refund or null to refund it all.
     * @param float           $brokerCommission      is the amount of broker commission to refund or null for all of it.
     * @param string          $notes                 the notes to put on the refund.
     * @param string          $source                is the source to say on the payment.
     * @return CheckoutPayment the new refund.
     */
    public function refund(
        CheckoutPayment $payment,
        $amount = null,
        $coverholderCommission = null,
        $brokerCommission = null,
        $notes = null,
        $source = null
    ) {
        $amount = $amount ?: $payment->getAmount();
        $coverholderCommission = $coverholderCommission ?: $payment->getCoverholderCommission();
        $brokerCommission = $brokerCommission ?: $payment->getBrokerCommission();
        $policy = $payment->getPolicy();
        // Refund is a negative payment
        $refund = new CheckoutPayment();
        $refund->setAmount(0 - $amount);
        $refund->setCommission(0 - $coverholderCommission, 0 - $brokerCommission);
        $refund->setNotes($notes);
        $refund->setSource($source);
        if ($policy->getCheckoutPaymentMethod()) {
            $payment->setDetails($policy->getCheckoutPaymentMethod()->__toString());
        }
        $policy->addPayment($refund);
        $this->dm->persist($refund);
        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        $client = $this->getClientForPolicy($policy);
        $chargeService = $client->chargeService();
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

        $this->dm->flush(null, array('w' => 'majority', 'j' => true));

        return $refund;
    }

    /**
     * Creates a card token on checkout.
     * @param Policy $policy     is the policy that the card belongs on.
     * @param string $cardNumber is the number on the card the token is being created for.
     * @param string $expiryDate is the expiry date written on the card.
     * @param string $cv2        is the cv2 number on the card.
     * @return \Checkout\Models\Tokens\Card the token which checkout sends back.
     */
    public function createCardToken($policy, $cardNumber, $expiryDate, $cv2)
    {
        $token = null;
        try {
            $exp = explode('/', $expiryDate);
            $cardNumber = str_replace(' ', '', $cardNumber);
            $card = new \Checkout\Models\Tokens\Card($cardNumber, $exp[0], $exp[1]);
            $card->cvv = $cv2;
            $api = $this->getApiForPolicy($policy);
            $service = $api->tokens();
            $token = $service->request($card);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed creating card token. Msg: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }
        return $token;
    }

    public function tokenPay(
        Policy $policy,
        $amount = null,
        $notes = null,
        $abortOnMultipleSameDayPayment = true,
        \DateTime $date = null,
        $recurring = false
    ): CheckoutPayment {
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

    public function processCsv(CheckoutFile $checkoutFile): array
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
     * Sets the commission for a payment.
     * @param CheckoutPayment $payment       is the payment to set commission for.
     * @param bool            $allowFraction is whether to allow fractional commission for this payment.
     */
    public function setCommission($payment, $allowFraction = false): void
    {
        try {
            $payment->getPolicy()->setCommission($payment, $allowFraction);
        } catch (CommissionException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * Should only be called if payment is successful (e.g. card is not already expired)
     * @param Policy    $policy
     * @param \DateTime $date
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
     * Sends an email notification regarding a failed payment.
     * @param Policy    $policy         policy the email regards.
     * @param int       $failedPayments number of consecutive failed payments
     * @param \DateTime $next           date the next payment will be taken.
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
     * Sends an sms notification regarding a failed payment.
     * @param Policy    $policy         policy the sms regards.
     * @param int       $failedPayments number of consecutive failed payments at this time.
     * @param \DateTime $next           date when the next payment will be taken.
     */
    public function failedPaymentSms(Policy $policy, $failedPayments, \DateTime $next = null): void
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

    /**
     * Sets a policy's card token using the given card details.
     * @param Policy $policy     is the policy for whom to set the card token.
     * @param string $customerId is the customer id associated with the card.
     * @param array  $card       is the array of card details.
     */
    private function setCardToken(Policy $policy, $customerId, $card): void
    {
        /** @var CheckoutPaymentMethod $checkoutPaymentMethod */
        $checkoutPaymentMethod = $policy->getCheckoutPaymentMethod();
        if (!$checkoutPaymentMethod) {
            $checkoutPaymentMethod = new CheckoutPaymentMethod();
            $policy->setPaymentMethod($checkoutPaymentMethod);
        }
        if (!$checkoutPaymentMethod->getCustomerId() || $checkoutPaymentMethod->getCustomerId() !== $customerId) {
            $checkoutPaymentMethod->setCustomerId($customerId);
        }
        $tokens = $checkoutPaymentMethod->getCardTokens();
        $cardDetails = [
            'cardLastFour' => $card['last4'],
            'endDate' => sprintf('%02d%02d', $card['expiry_month'], mb_substr($card['expiry_year'], 2, 2)),
            'cardType' => $card['card_type'],
            'fingerprint' => $card['fingerprint']
        ];
        $checkoutPaymentMethod->addCardToken($card['id'], json_encode($cardDetails));
    }

    private function getCheckoutAddress(User $user): Address
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

    private function triggerPaymentEvent($payment): void
    {
        if (!$payment) {
            return;
        }
        if ($this->dispatcher) {
            if ($payment->isSuccess()) {
                $this->logger->debug('Event Payment Success');
                $this->dispatcher->dispatch(PaymentEvent::EVENT_SUCCESS, new PaymentEvent($payment));
            } else {
                $this->logger->debug('Event Payment Failed');
                $this->dispatcher->dispatch(PaymentEvent::EVENT_FAILED, new PaymentEvent($payment));
            }
        } else {
            $this->logger->warning('Dispatcher is disabled for Checkout Service');
        }
    }

    private function triggerPolicyEvent($policy, $event, \DateTime $date = null): void
    {
        if (!$policy) {
            return;
        }
        if ($this->dispatcher) {
            $this->logger->debug(sprintf('Event %s', $event));
            $this->dispatcher->dispatch($event, new PolicyEvent($policy, $date));
        } else {
            $this->logger->warning('Dispatcher is disabled for Checkout Service');
        }
    }

    private function validateUser($user)
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

    private function validatePaymentAmount(CheckoutPayment $payment)
    {
        $premium = $payment->getPolicy()->getPremium();
        if (!$premium && !in_array(
            $payment->getPolicy()->getStatus(),
            [Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID]
        )) {
            return;
        }
        if (!$premium->isEvenlyDivisible($payment->getAmount()) &&
            !$premium->isEvenlyDivisible($payment->getAmount(), true) &&
            !$this->areEqualToTwoDp($payment->getAmount(), $payment->getPolicy()->getOutstandingPremium()) &&
            !$this->areEqualToTwoDp($payment->getAmount(), $payment->getPolicy()->getUpgradedStandardMonthlyPrice()) &&
            !$this->areEqualToTwoDp($payment->getAmount(), $payment->getPolicy()->getUpgradedFinalMonthlyPrice())
        ) {
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
    }

    private function createPayment(
        Policy $policy,
        string $chargeId,
        string $source,
        \DateTime $date = null
    ): CheckoutPayment {
        $this->logger->error($chargeId);
        $user = $policy->getUser();
        $payment = $this->validateCharge($policy, $chargeId, $source, $date);
        $this->triggerPaymentEvent($payment);
        $this->validateUser($user);
        return $payment;
    }
}
