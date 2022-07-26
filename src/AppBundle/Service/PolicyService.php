<?php

namespace AppBundle\Service;

use AppBundle\Classes\SoSure;
use AppBundle\Classes\Salva;
use AppBundle\Classes\NoOp;
use AppBundle\Document\Address;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Feature;
use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\Reward;
use AppBundle\Exception\ValidationException;
use AppBundle\Repository\PolicyTermsRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Repository\CashbackRepository;
use AppBundle\Repository\OptOut\EmailOptOutRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\PolicyRepository;
use Aws\S3\S3Client;
use CensusBundle\Service\SearchService;
use AppBundle\Document\LogEntry;
use AppBundle\Repository\LogEntryRepository;
use DateInterval;
use DateTime;
use Knp\Bundle\SnappyBundle\Snappy\LoggableGenerator;
use Knp\Snappy\AbstractGenerator;
use Knp\Snappy\GeneratorInterface;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use AppBundle\Document\Cashback;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Promotion;
use AppBundle\Document\Participation;
use AppBundle\Document\SCode;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\File\PolicyTermsFile;
use AppBundle\Document\File\PolicyScheduleFile;
use AppBundle\Document\File\S3File;

use AppBundle\Service\SalvaExportService;
use AppBundle\Service\PriceService;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\UserPaymentEvent;

use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\InvalidUserDetailsException;
use AppBundle\Exception\GeoRestrictedException;
use AppBundle\Exception\DuplicateImeiException;
use AppBundle\Exception\LostStolenImeiException;
use AppBundle\Exception\InvalidImeiException;
use AppBundle\Exception\ImeiBlacklistedException;
use AppBundle\Exception\ImeiPhoneMismatchException;
use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\AlreadyParticipatingException;

use Symfony\Component\Templating\EngineInterface;

class PolicyService
{
    use CurrencyTrait;
    use DateTrait;

    const KEY_POLICY_QUEUE = 'policy:queue';
    const KEY_PREVENT_CANCELLATION = 'policy:prevent-cancellation:%s';
    const CACHE_PREVENT_CANCELLATION = 43200; // 12 hours

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var SequenceService */
    protected $sequence;

    /** @var MailerService */
    protected $mailer;

    /** @var \Swift_Transport */
    protected $smtp;

    /** @var EngineInterface */
    protected $templating;

    /** @var RouterService */
    protected $routerService;

    /** @var LoggableGenerator */
    protected $snappyPdf;

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $environment;

    /** @var boolean */
    protected $skipS3;

    /** @var ShortLinkService */
    protected $shortLink;

    /** @var \Domnikl\Statsd\Client */
    protected $statsd;

    /** @var Client */
    protected $redis;

    /** @var BranchService */
    protected $branch;

    /** @var SearchService $searchService */
    protected $searchService;

    protected $imeiValidator;

    protected $rateLimit;

    protected $intercom;

    /** @var SmsService */
    protected $sms;

    /** @var SCodeService */
    protected $scodeService;

    /** @var FeatureService */
    protected $featureService;

    /** @var PriceService */
    protected $priceService;

    /** @var PostcodeService */
    protected $postcodeService;

    /** @var CheckoutService */
    protected $checkoutService;

    protected $warnMakeModelMismatch = true;

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    public function setMailerMailer($mailer)
    {
        $this->mailer->setMailer($mailer);
    }

    public function setDispatcher($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function setWarnMakeModelMismatch($warnMismatch)
    {
        $this->warnMakeModelMismatch = $warnMismatch;
    }

    /**
     * Environment is injected into constructed and should only
     * be overwriten for a few test cases.
     *
     * @param string $environment
     */
    public function setEnvironment($environment)
    {
        $this->environment = $environment;
        $this->skipS3 = true;
    }

    /**
     * @param DocumentManager          $dm
     * @param LoggerInterface          $logger
     * @param SequenceService          $sequence
     * @param MailerService            $mailer
     * @param \Swift_Transport         $smtp
     * @param EngineInterface          $templating
     * @param RouterService            $routerService
     * @param string                   $environment
     * @param LoggableGenerator        $snappyPdf
     * @param EventDispatcherInterface $dispatcher
     * @param S3Client                 $s3
     * @param ShortLinkService         $shortLink
     * @param \Domnikl\Statsd\Client   $statsd
     * @param Client                   $redis
     * @param BranchService            $branch
     * @param SearchService            $searchService
     * @param ReceperioService         $imeiValidator
     * @param RateLimitService         $rateLimit
     * @param IntercomService          $intercom
     * @param SmsService               $sms
     * @param SCodeService             $scodeService
     * @param FeatureService           $featureService
     * @param PriceService             $priceService
     * @param PostcodeService          $postcodeService
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        SequenceService $sequence,
        MailerService $mailer,
        \Swift_Transport $smtp,
        EngineInterface $templating,
        RouterService $routerService,
        $environment,
        LoggableGenerator $snappyPdf,
        EventDispatcherInterface $dispatcher,
        S3Client $s3,
        ShortLinkService $shortLink,
        \Domnikl\Statsd\Client $statsd,
        Client $redis,
        BranchService $branch,
        SearchService $searchService,
        ReceperioService $imeiValidator,
        RateLimitService $rateLimit,
        IntercomService $intercom,
        SmsService $sms,
        SCodeService $scodeService,
        FeatureService $featureService,
        PriceService $priceService,
        PostcodeService $postcodeService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->sequence = $sequence;
        $this->mailer = $mailer;
        $this->smtp = $smtp;
        $this->templating = $templating;
        $this->routerService = $routerService;
        $this->environment = $environment;
        $this->snappyPdf = $snappyPdf;
        $this->dispatcher = $dispatcher;
        $this->s3 = $s3;
        $this->shortLink = $shortLink;
        $this->statsd = $statsd;
        $this->redis = $redis;
        $this->branch = $branch;
        $this->searchService = $searchService;
        $this->imeiValidator = $imeiValidator;
        $this->rateLimit = $rateLimit;
        $this->intercom = $intercom;
        $this->sms = $sms;
        $this->scodeService = $scodeService;
        $this->featureService = $featureService;
        $this->priceService = $priceService;
        $this->postcodeService = $postcodeService;
    }

    /**
     * Sets the checkout service that this service will use. It is necessary to make it possible for this service to be
     * constructed without a checkoutservice because both need each other and so there would be a circular dependency
     * at the time of construction.
     * @param CheckoutService $checkoutService is the checkout service to use.
     */
    public function setCheckoutService($checkoutService)
    {
        $this->checkoutService = $checkoutService;
    }

    public function validateUser(User $user)
    {
        if (!$user->hasValidDetails() || !$user->hasValidBillingDetails()) {
            throw new InvalidUserDetailsException();
        }

        /** @var Address $address */
        $address = $user->getBillingAddress();
        if (!$address || !$this->searchService->validatePostcode($address->getPostcode())) {
            // If the postcode in not in our database it might still be valid
            if (!$this->postcodeService->isValidPostcode($address->getPostcode())) {
                throw new GeoRestrictedException();
            }
        }

        if ($this->postcodeService->getIsBannedPostcode($address->getPostcode())) {
            throw new GeoRestrictedException();
        }
    }

    private function validateImei($imei)
    {
        if (!$this->imeiValidator->isImei($imei)) {
            throw new InvalidImeiException();
        }
        if ($this->imeiValidator->isLostImei($imei)) {
            throw new LostStolenImeiException();
        }
        if ($this->imeiValidator->isDuplicatePolicyImei($imei)) {
            throw new DuplicateImeiException();
        }
    }

    private function checkImeiSerial(User $user, Phone $phone, $imei, $serialNumber, IdentityLog $identityLog = null)
    {
        $checkmend = [];
        // Checking against blacklist should be last check to possible avoid costs
        if (!$this->imeiValidator->checkImei($phone, $imei, $user, $identityLog)) {
            throw new ImeiBlacklistedException();
        }
        $checkmend['imeiCertId'] = $this->imeiValidator->getCertId();
        $checkmend['imeiResponse'] = $this->imeiValidator->getResponseData();

        // Get the model for 'iPhone SE Workaround'
        $model = $phone->getModel();

        if (!$this->imeiValidator->checkSerial(
            $phone,
            $serialNumber,
            $imei,
            $user,
            $identityLog,
            $this->warnMakeModelMismatch
        ) && $model != 'iPhone SE (2020)') {
            throw new ImeiPhoneMismatchException();
        }

        $checkmend['serialResponse'] = $this->imeiValidator->getResponseData();
        $checkmend['makeModelValidatedStatus'] = $this->imeiValidator->getMakeModelValidatedStatus();

        return $checkmend;
    }

    public function init(
        User $user,
        Phone $phone,
        $imei = null,
        $serialNumber = null,
        IdentityLog $identityLog = null,
        $phoneData = null,
        $modelNumber = null,
        $aggregatorAttribution = null,
        $subvariant = null
    ) {
        try {
            $this->validateUser($user);
            if ($imei) {
                $this->validateImei($imei);
            }

            if ($identityLog && $identityLog->isSessionDataPresent()) {
                if (!$this->rateLimit->allowedByDevice(
                    RateLimitService::DEVICE_TYPE_POLICY,
                    $identityLog->getIp(),
                    $identityLog->getCognitoId()
                )) {
                    throw new RateLimitException();
                }
            }

            $checkmend = null;
            if ($imei && !$user->hasSoSureEmail()) {
                $checkmend = $this->checkImeiSerial($user, $phone, $imei, $serialNumber, $identityLog);
            }

            $date = new DateTime();
            $policy = null;
            if ($date > Salva::getSalvaBinderEndDate()) {
                $policy = new HelvetiaPhonePolicy();
            } else {
                $policy = new SalvaPhonePolicy();
            }
            $policy->setPhone($phone);
            if ($imei) {
                $policy->setImei($imei);
            }
            $policy->setSerialNumber($serialNumber);
            $policy->setModelNumber($modelNumber);
            $policy->setIdentityLog($identityLog);
            $policy->setPhoneData($phoneData);
            if ($subvariant) {
                $policy->setSubvariant($subvariant);
            }
            if ($aggregatorAttribution) {
                $policy->setAggregatorAttribution($aggregatorAttribution);
            }
            /** @var PolicyTermsRepository $policyTermsRepo */
            $policyTermsRepo = $this->dm->getRepository(PolicyTerms::class);
            /** @var PolicyTerms $latestTerms */
            $latestTerms = $policyTermsRepo->findLatestTerms();
            $policy->init($user, $latestTerms);
            if ($checkmend) {
                $policy->addCheckmendCertData($checkmend['imeiCertId'], $checkmend['imeiResponse']);
                $policy->addCheckmendSerialData($checkmend['serialResponse']);
                // saving final finaly checkmendcert based status
                $policy->setMakeModelValidatedStatus($checkmend['makeModelValidatedStatus']);
            }

            try {
                $this->dispatchEvent(PolicyEvent::EVENT_INIT, new PolicyEvent($policy));
            } catch (\Exception $e) {
                $this->logger->warning(
                    sprintf('Failed to dispatch init event for user %s', $user->getId()),
                    ['exception' => $e]
                );
            }

            return $policy;
        } catch (InvalidPremiumException $e) {
            $this->logger->warning(
                sprintf('Failed to init policy for user %s', $user->getId()),
                ['exception' => $e]
            );
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'Invalid premium')
            );
            throw $e;
        } catch (InvalidUserDetailsException $e) {
            $this->logger->warning(
                sprintf('Failed to init policy for user %s', $user->getId()),
                ['exception' => $e]
            );
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'Invalid User Details')
            );
            throw $e;
        } catch (GeoRestrictedException $e) {
            $this->logger->warning(
                sprintf('Failed to init policy for user %s', $user->getId()),
                ['exception' => $e]
            );
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'Non-UK Address')
            );
            throw $e;
        } catch (DuplicateImeiException $e) {
            $this->logger->warning(
                sprintf('Failed to init policy for user %s', $user->getId()),
                ['exception' => $e]
            );
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'IMEI Already In System')
            );
            throw $e;
        } catch (LostStolenImeiException $e) {
            $this->logger->warning(
                sprintf('Failed to init policy for user %s', $user->getId()),
                ['exception' => $e]
            );
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'IMEI Lost Or Stolen (so-sure)')
            );
            throw $e;
        } catch (ImeiBlacklistedException $e) {
            $this->logger->warning(
                sprintf('Failed to init policy for user %s', $user->getId()),
                ['exception' => $e]
            );
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'IMEI Blacklisted')
            );
            throw $e;
        } catch (InvalidImeiException $e) {
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'IMEI Is Invalid')
            );
            throw $e;
        } catch (ImeiPhoneMismatchException $e) {
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'IMEI/Serial Does Not Match Receperio Make/Model')
            );
            throw $e;
        } catch (RateLimitException $e) {
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'Rate Limited')
            );
            throw $e;
        }
    }

    public function validateUpgradeImei(User $user, Phone $phone, $imei = null, $serialNumber = null)
    {
        $this->validateImei($imei);
        return $this->checkImeiSerial($user, $phone, $imei, $serialNumber);
    }

    private function dispatchEvent($eventType, $event)
    {
        if ($this->dispatcher) {
            $this->dispatcher->dispatch($eventType, $event);
        } else {
            $this->logger->warning('Dispatcher is disabled for Policy Service');
        }
    }

    public function create(
        Policy $policy,
        DateTime $date = null,
        $setActive = false,
        $numPayments = null,
        IdentityLog $identityLog = null,
        $billing = null,
        $bacs = false
    ) {
        $this->statsd->startTiming("policy.create");
        try {
            if (!$date) {
                $date = DateTime::createFromFormat('U', time());
            }
            $user = $policy->getUser();

            $prefix = $policy->getPolicyPrefix();
            if ($policy->isValidPolicy()) {
                $this->logger->warning(sprintf('Policy %s is valid, but attempted to re-create', $policy->getId()));

                return false;
            }
            // validate the IMEI if this is a phone policy.
            if ($policy instanceof PhonePolicy &&
                $this->imeiValidator->isDuplicatePolicyImei($policy->getImei(), $policy)
            ) {
                throw new DuplicateImeiException("Given IMEI '".$policy->getImei()."' is already in use.");
            }

            if (count($policy->getScheduledPayments()) > 0) {
                throw new \Exception(sprintf('Policy %s is not valid, yet has scheduled payments', $policy->getId()));
            }

            // If policy hasn't yet been assigned a payer, default to the policy user
            if (!$policy->getPayer()) {
                $user->addPayerPolicy($policy);
            }

            if ($numPayments === null) {
                $dateToBill = $date;
                if ($billing) {
                    $dateToBill = $billing;
                }
                $this->generateScheduledPayments($policy, $dateToBill, $date, $numPayments, null, false, $bacs);
                $policy->arePolicyScheduledPaymentsCorrect(true);
            } else {
                $policy->setPremiumInstallments($numPayments);
            }

            // Generate/set scode prior to creating policy as policy create has a fallback scode creation
            $scode = null;
            foreach ($user->getAllPolicies() as $loopPolicy) {
                if ($loopPolicy->getId() != $policy->getId() &&
                    $scode = $loopPolicy->getStandardSCode()) {
                    $scode = clone $scode;
                    $policy->addSCode($scode);
                    break;
                }
            }

            if (!$scode) {
                if ($scode = $policy->getStandardSCode()) {
                    // scode created during the policy generation should not yet be persisted to the db
                    // so if it does exist, its a duplicate code
                    $scodeRepo = $this->dm->getRepository(SCode::class);
                    $exists = $scodeRepo->findOneBy(['code' => $scode->getCode()]);
                    if ($exists) {
                        // removing scode from policy seems to be problematic, so change code and make inactive
                        $scode->deactivate();
                    }
                }
                $scode = $this->scodeService->generateSCode($policy->getUser(), SCode::TYPE_STANDARD);
                $policy->addSCode($scode);
            }

            if ($prefix && !$policy->getSubvariant()) {
                $policy->create(
                    $this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE_INVALID),
                    $prefix,
                    $date,
                    1,
                    $billing
                );
            } else {
                $prefix = $policy->getSubvariant() ? $policy->getSubvariant()->getPolicyPrefix() : null;
                $policy->create(
                    $this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE),
                    $prefix,
                    $date,
                    1,
                    $billing
                );
            }

            $this->setPromoCode($policy);
            if ($identityLog) {
                $policy->setIdentityLog($identityLog);
            }

            $this->dm->flush();

            $this->queueMessage($policy);

            if ($setActive) {
                $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
                $this->dm->flush();
            }

            // Dispatch should be last as there may be events that assume the policy is active
            // (e.g. intercom)
            $this->dispatchEvent(PolicyEvent::EVENT_CREATED, new PolicyEvent($policy));
            if ($setActive) {
                $this->dispatchEvent(PolicyEvent::EVENT_START, new PolicyEvent($policy));
            }
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error creating policy %s', $policy->getId()), ['exception' => $e]);
            throw $e;
        }

        /**
         * Finally, before we leave the create method, we should check if
         * there is a sign-up bonus available to automatically apply to
         * the policy as it is not a renewal here.
         */
        $featureService = new FeatureService($this->dm, $this->logger);
        if ($featureService->isEnabled(Feature::FEATURE_APPLY_SIGN_UP_BONUS)) {
            $rewardService = new RewardService($this->dm, $this->logger);
            /** @var Reward $reward */
            $reward = $rewardService->getSignUpBonus();
            if (null != $reward && !$policy->getCompany()) {
                $connection = new RewardConnection();
                $policy->addConnection($connection);
                $connection->setLinkedUser($reward->getUser());
                $connection->setPromoValue($reward->getDefaultValue());
                $reward->addConnection($connection);
                $reward->updatePotValue();
                $policy->updatePotValue();
                $this->dm->persist($connection);
                $this->dm->flush();
            }
        }

        $this->statsd->endTiming("policy.create");

        return true;
    }

    protected function setPromoCode($policy)
    {
        $promoCode = null;
        $isPreLaunchUser = $policy->getUser()->isPreLaunch();
        $isOct2016 = $policy->getStart()->format('Y-m') == '2016-10';
        $isNov2016 = $policy->getStart()->format('Y-m') == '2016-11';
        $isDec2016 = $policy->getStart()->format('Y-m') == '2016-12';

        // Prelaunch Policy is being discontinued as of end Oct 2016
        // This was only advertised after policy purchase, so can be discontinued for future policies
        // And manually added on request
        $isPreLaunchPolicy = false;
        if ($policy instanceof PhonePolicy) {
            /** @var PhonePolicyRepository $repo */
            $repo = $this->dm->getRepository(PhonePolicy::class);
            $isPreLaunchPolicy = $repo->isPromoLaunch();
        }

        if ($isOct2016 && ($isPreLaunchPolicy || $isPreLaunchUser)) {
            $promoCode = Policy::PROMO_LAUNCH;
        } elseif ($isNov2016) {
            $promoCode = Policy::PROMO_FREE_NOV;
        } elseif ($isDec2016) {
            $promoCode = Policy::PROMO_FREE_DEC_2016;
        }

        $policy->setPromoCode($promoCode);
    }

    public function queueMessage($policy)
    {
        $data = ['policyId' => $policy->getId()];
        $this->redis->rpush(self::KEY_POLICY_QUEUE, serialize($data));
    }

    public function clearQueue($max = null)
    {
        if (!$max) {
            $this->redis->del([self::KEY_POLICY_QUEUE]);
        } else {
            for ($i = 0; $i < $max; $i++) {
                $this->redis->lpop(self::KEY_POLICY_QUEUE);
            }
        }
    }

    public function getQueueSize()
    {
        return $this->redis->llen(self::KEY_POLICY_QUEUE);
    }

    public function getQueueData($max)
    {
        return $this->redis->lrange(self::KEY_POLICY_QUEUE, 0, $max - 1);
    }

    public function process($max)
    {
        $count = 0;
        while ($count < $max) {
            $policy = null;
            try {
                $queueItem = $this->redis->lpop(self::KEY_POLICY_QUEUE);
                if (!$queueItem) {
                    return $count;
                }
                $data = unserialize($queueItem);

                if (!isset($data['policyId'])) {
                    throw new \Exception(sprintf('Unknown message in queue %s', json_encode($data)));
                }
                $policyRepo = $this->dm->getRepository(Policy::class);
                $policy = $policyRepo->find($data['policyId']);
                if (!$policy) {
                    throw new \Exception(sprintf('Unknown policy in queue %s', json_encode($data)));
                }

                $this->generatePolicyFiles($policy, true);

                $count = $count + 1;
            } catch (\Exception $e) {
                $this->logger->error(sprintf(
                    'Error reprocessing policy message [%s]',
                    json_encode($data)
                ), ['exeception' => $e]);

                throw $e;
            }
        }

        return $count;
    }

    public function generatePolicyFiles($policy, $email = true, $bcc = null)
    {
        $this->statsd->startTiming("policy.schedule+terms");
        $policyTerms = $this->generatePolicyTerms($policy);
        $policySchedule = $this->generatePolicySchedule($policy);
        $this->dm->flush();
        $this->statsd->endTiming("policy.schedule+terms");

        if ($email) {
            if ($policy->isUpgraded()) {
                $this->upgradedPolicyEmail($policy, [$policySchedule, $policyTerms], $bcc);
            } else {
                $this->newPolicyEmail($policy, [$policySchedule, $policyTerms], $bcc);
            }
        }
    }

    public function generatePolicyTerms(Policy $policy)
    {
        $filename = sprintf(
            "%s-%s.pdf",
            "policy",
            str_replace('/', '-', $policy->getPolicyNumber())
        );
        $tmpFile = sprintf(
            "%s/%s",
            sys_get_temp_dir(),
            $filename
        );
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        if (!$policy->getPolicyTerms()->getVersionNumber()) {
            throw new \Exception('Unable to determine policy version');
        }

        $template = sprintf(
            'AppBundle:Pdf:policyTermsV%d.html.twig',
            $policy->getPolicyTerms()->getVersionNumber()
        );

        $this->snappyPdf->setOption('orientation', 'Portrait');
        $this->snappyPdf->setOption('lowquality', false);
        $this->snappyPdf->setOption('footer-center', sprintf(
            '%s (Page [page] of [topage])',
            $policy->getPolicyTerms()->getVersion()
        ));
        //$this->snappyPdf->setOption('footer-center', $policy->getPolicyTerms()->getVersion());
        $this->snappyPdf->setOption('footer-font-size', 8);

        $this->snappyPdf->setOption('page-size', 'A4');
        // $this->snappyPdf->setOption('margin-top', '20mm');
        // $this->snappyPdf->setOption('margin-bottom', '10');
        // $this->snappyPdf->setOption('zoom', '1.25');
        //$this->snappyPdf->setOption('dpi', '300');
        $this->snappyPdf->generateFromHtml(
            $this->templating->render($template, [
                'policy' => $policy,
                'claims_default_direct_group' => $this->featureService->isEnabled(
                    Feature::FEATURE_CLAIMS_DEFAULT_DIRECT_GROUP
                ),
            ]),
            $tmpFile
        );

        $this->uploadS3($tmpFile, $filename, $policy);

        $policyTermsFile = new PolicyTermsFile();
        $policyTermsFile->setBucket(SoSure::S3_BUCKET_POLICY);
        $policyTermsFile->setKey($this->getS3Key($policy, $filename));
        $policy->addPolicyFile($policyTermsFile);

        return $tmpFile;
    }

    public function generatePolicySchedule(Policy $policy)
    {
        $filename = sprintf(
            "%s-%s.pdf",
            "policy-schedule",
            str_replace('/', '-', $policy->getPolicyNumber())
        );
        $tmpFile = sprintf(
            "%s/%s",
            sys_get_temp_dir(),
            $filename
        );
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        $template = sprintf(
            'AppBundle:Pdf:policyScheduleV%d%s.html.twig',
            $policy->getPolicyTerms()->getVersionNumber(),
            $policy->getPolicyTerms()->isPicSureRequired() ? "_R" : ""
        );

        $this->snappyPdf->setOption('orientation', 'Portrait');
        $this->snappyPdf->setOption('page-size', 'A4');
        // $this->snappyPdf->setOption('margin-top', '20mm');
        $this->snappyPdf->generateFromHtml(
            $this->templating->render($template, ['policy' => $policy]),
            $tmpFile
        );

        $this->uploadS3($tmpFile, $filename, $policy);

        $policyScheduleFile = new PolicyScheduleFile();
        $policyScheduleFile->setBucket(SoSure::S3_BUCKET_POLICY);
        $policyScheduleFile->setKey($this->getS3Key($policy, $filename));
        $policy->addPolicyFile($policyScheduleFile);

        return $tmpFile;
    }

    public function getS3Key($policy, $filename)
    {
        return sprintf('%s/mob/%s/%s', $this->environment, $policy->getId(), $filename);
    }

    public function uploadS3($file, $filename, Policy $policy)
    {
        if ($this->environment == "test" || $this->skipS3) {
            return;
        }

        $s3Key = $this->getS3Key($policy, $filename);

        $result = $this->s3->putObject(array(
            'Bucket' => SoSure::S3_BUCKET_POLICY,
            'Key'    => $s3Key,
            'SourceFile' => $file,
        ));
    }

    /**
     * Changes the billing day of a policy and reschedules all it's scheduled payments to be on that day.
     * @param Policy $policy is the policy to change the billing day for.
     * @param int    $day    is the day of the month to set it to.
     */
    public function changeBillingDay(Policy $policy, $day)
    {
        if ($policy->getPaymentMethod() instanceof BacsPaymentMethod) {
            throw new \InvalidArgumentException('No change of billing day for bacs policies');
        }
        $policy->setBilling(DateTrait::setDayOfMonth($policy->getBilling(), $day));
        $scheduledPayments = $policy->getScheduledPayments();
        foreach ($scheduledPayments as $scheduledPayment) {
            if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED) {
                $scheduledPayment->setScheduled(DateTrait::setDayOfMonth(
                    $scheduledPayment->getScheduled(),
                    $day
                ));
            }
        }
        $this->dm->flush();
    }

    /**
     * If returns false, make sure to re-load policy
     * $policy = $this->dm->merge($policy);
     * TODO: Fix that
     */
    public function adjustScheduledPayments(Policy $policy, $expectSingleAdjustment = false)
    {
        $log = [];
        if ($policy->arePolicyScheduledPaymentsCorrect()) {
            return null;
        }

        // Flush the manager, so we only will have the scheduled payments in the changeset in case we need to clear it
        $this->dm->flush();

        // Ensure that billing dates are updated
        /** @var ScheduledPayment $scheduledPayment */
        foreach ($policy->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED) as $scheduledPayment) {
            if ($scheduledPayment->hasCorrectBillingDay() === false) {
                $adjustedScheduledDay = $this->setDayOfMonth(
                    $scheduledPayment->getScheduled(),
                    $policy->getBillingDay()
                );
                $scheduledPayment->setScheduled($adjustedScheduledDay);
            }
        }

        if ($policy->arePolicyScheduledPaymentsCorrect()) {
            $this->dm->flush();
            return null;
        }

        $scheduledPayments = [];
        // Try cancellating scheduled payments until amount matches
        $i = 0;
        while (!$policy->arePolicyScheduledPaymentsCorrect()) {
            $scheduledPayment = $policy->getNextScheduledPayment();
            // shouldn't be more than 12 payments, use 24 just in case to prevent infinite loop
            if ($scheduledPayment === null || $i > 24) {
                break;
            }

            $scheduledPayments[] = $scheduledPayment;
            $scheduledPayment->cancel('Cancelled as part of adjustment due to incorrect scheduled payments.');
            $log[] = sprintf(
                'For Policy %s, cancelled scheduled payment %s on %s for £%0.2f',
                $policy->getPolicyNumber(),
                $scheduledPayment->getId(),
                $scheduledPayment->getScheduled() ? $scheduledPayment->getScheduled()->format(DateTime::ATOM) : '?',
                $scheduledPayment->getAmount()
            );
            $i++;
        }

        if ($policy->arePolicyScheduledPaymentsCorrect()) {
            $this->dm->flush();
            // If user has manually paid, there should be a single adjustment made, so reduce log level
            if ($expectSingleAdjustment && count($scheduledPayments) == 1) {
                $this->logger->info(implode(PHP_EOL, $log));
            } else {
                $this->logger->warning(implode(PHP_EOL, $log));
            }

            return true;
        } else {
            // Amount doesn't match - don't cancel scheduled payments

            // Merge entity doesn't seem to be resetting the count of scheduled payments,
            // so undue the cancelled
            foreach ($scheduledPayments as $scheduledPayment) {
                $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            }
            // Avoid persisting any changes (shouldn't be as we just reset the cancellation)
            $this->dm->clear();
            $this->logger->error(sprintf(
                'For Policy %s, unable to adjust scheduled payments to meet expected payment amount',
                $policy->getPolicyNumber()
            ));

            return false;
        }
    }

    public function swapPaymentPlan(Policy $policy)
    {
        if ($policy->getPremiumPaid() > 0) {
            throw new \Exception('Only able to swap payment plan when policy is unpaid');
        }

        if ($policy->getPremiumPlan() == Policy::PLAN_MONTHLY) {
            $policy->setPremiumInstallments(1);
            $this->dm->flush();
            $this->regenerateScheduledPayments($policy);
            $this->dm->flush();
        } elseif ($policy->getPremiumPlan() == Policy::PLAN_YEARLY) {
            $policy->setPremiumInstallments(12);
            $this->dm->flush();
            $this->regenerateScheduledPayments($policy);
            $this->dm->flush();
        }
    }

    /**
     * Cancels a policy's existing schedule of scheduled payments and creates a new schedule based on the current
     * state of the policy.
     * @param Policy   $policy        is the policy to regenerate the schedule for.
     * @param DateTime $date          is the point from which to begin regenerating the schedule, with null being the
     *                                policy start of billing.
     * @param DateTime $now           is to be considered the current date, with null being the system time.
     * @param int      $numPayments   is the number of payments desired.
     * @param number   $billingOffset is the amount of apparently owed money not to factor into schedule.
     */
    public function regenerateScheduledPayments(
        Policy $policy,
        DateTime $date = null,
        DateTime $now = null,
        $numPayments = null,
        $billingOffset = null
    ) {
        $policy->cancelScheduledPayments();
        $this->generateScheduledPayments($policy, $date, $now, $numPayments, $billingOffset);
    }

    /**
     * Creates a schedule of payments for the given policy.
     * @param Policy   $policy        is the policy to create the scheduled payments for.
     * @param DateTime $date          is the date at which to start the payments, null being the policy billing start.
     * @param DateTime $now           is the date to be considered the current date, which payments should not be able
     *                                to scheduled more than a few business days before, null being system time.
     * @param int      $numPayments   is the number of payments desired or null for this to be deduced.
     * @param number   $billingOffset is the amount of owed money not to add into the payment schedule.
     * @param boolean  $renewal       is whether this policy is a renewal and thus should have a scheduled payment
     *                                right at their beginning.
     */
    public function generateScheduledPayments(
        Policy $policy,
        DateTime $date = null,
        DateTime $now = null,
        $numPayments = null,
        $billingOffset = null,
        $renewal = false,
        $bacs = false
    ) {
        if (!$now) {
            $now = new DateTime();
        }
        if ($policy->getBilling()) {
            $date = clone $policy->getBilling();
        }

        $date->setTimezone(SoSure::getSoSureTimezone());
        $date->setTime(3, 0);

        $paymentItem = null;
        if (!$numPayments) {
            if ($policy->getPremiumInstallments()) {
                $numPayments = $policy->getPremiumInstallments();
            } elseif ($paymentItem = $policy->getLastSuccessfulUserPaymentCredit()) {
                $premium = $policy->getPremium();
                $numPayments = $premium->getNumberOfScheduledMonthlyPayments($paymentItem->getAmount());
            }
        }

        if (!$numPayments || $numPayments < 1 || $numPayments > 12) {
            throw new InvalidPremiumException(sprintf(
                'Invalid payment %f (%d) for policy %s [Expected %f or %f]',
                $paymentItem ? $paymentItem->getAmount() : null,
                $numPayments,
                $policy->getId(),
                $policy->getPremium()->getYearlyPremiumPrice(),
                $policy->getPremium()->getMonthlyPremiumPrice()
            ));
        }

        // premium installments must either be 1 or 12
        $policy->setPremiumInstallments($numPayments == 1 ? 1 : 12);
        $paid = $policy->getTotalSuccessfulPayments($date, true);
        if ($billingOffset) {
            $paid += $billingOffset;
        }
        $numPaidPayments = $policy->countSchedulePayments();
        if (!$numPaidPayments) {
            if ($paid > 0) {
                // There were some payments applied to the policy, but amounts don't split
                throw new \Exception(sprintf(
                    'Unable to determine correct payment schedule for policy %s (%f / %d)',
                    $policy->getId(),
                    $paid,
                    $numPaidPayments
                ));
            }
            $numPaidPayments = 0;
        }
        $isBacs = $bacs || $policy->getPaymentMethod() instanceof BacsPaymentMethod;
        $numScheduledPayments = $numPayments;
        for ($i = $numPaidPayments; $i < $numScheduledPayments; $i++) {
            $scheduledDate = clone $date;
            $pendingPayments = $policy->getAllScheduledPayments('pending');
            $pendingDates = [];
            /** @var ScheduledPayment $pendingPayment */
            foreach ($pendingPayments as $pendingPayment) {
                if ($pendingPayment->getScheduled()) {
                    $pendingDates[] = $pendingPayment->getScheduled()->format('Ymd');
                }
            }
            /**
             * If this is the initial payment and it is bacs, it should be 7 days from today
             * regardless of the billing date the customer has set.
             * Unless it is a renewal policy
             */
            if ($numPaidPayments == 0 && $isBacs && $i == 0 && !$renewal) {
                $scheduledDate = (clone $now)->add(new DateInterval("P7D"));
                if (in_array($scheduledDate->format('Ymd'), $pendingDates)) {
                    continue;
                }
            } elseif ($renewal && $i == 0) {
                $scheduledDate = $this->adjustDayForBilling($policy->getStart(), true);
            } else {
                $scheduledDate = $this->adjustDayForBilling($scheduledDate, true);
                if (in_array($scheduledDate->format('Ymd'), $pendingDates) && $isBacs) {
                    continue;
                }
                $scheduledDate->add(new DateInterval(sprintf('P%dM', $i)));
            }
            if ($isBacs) {
                try {
                    $scheduledDate = $this->adjustPaymentForBankHolidayAndWeekend($scheduledDate);
                } catch (\Exception $e) {
                    $this->logger->error(
                        sprintf(
                            "Scheduled payment for date %s - weekend or bank holiday adjustment failed",
                            $scheduledDate
                        )
                    );
                    throw $e;
                }
            }

            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setScheduled($scheduledDate);
            if ($i == 0 && $numPayments == 1) {
                $scheduledPayment->setAmount($policy->getUpgradedYearlyPrice());
            } elseif ($i < $numScheduledPayments - 1) {
                $scheduledPayment->setAmount($policy->getUpgradedStandardMonthlyPrice());
            } else {
                $scheduledPayment->setAmount($policy->getUpgradedFinalMonthlyPrice());
            }
            $policy->addScheduledPayment($scheduledPayment);
        }
        $this->dm->flush();
    }

    /**
     * Can add up to 4 days (1-2 days at a time) to a scheduled date depending on the date.
     * Sunday will add one day
     * Saturday will add two days
     * Bank holiday Monday will add one day
     * Bank holiday Friday will add 1 day.
     * Largest addition will be:
     * Original schedule date is bank holiday Friday
     * add one day and move to Saturday - recurse
     * Add two days and move to bank holiday Monday - recurse
     * Add one day and move to Tuesday.
     *
     * @param DateTime $date
     * @return DateTime
     * @throws \Exception
     */
    public function adjustPaymentForBankHolidayAndWeekend(DateTime $date)
    {
        /**
         * If the scheduled date is a weekend, we want to move it to the next Monday.
         * date format w returns 0-6 for Sunday to Saturday.
         * If we get 0 we want to add 1 day, if we get 6, we want to add 2 days.
         */
        if (!static::isWeekDay($date)) {
            $weekendInterval = $date->format('w') == 0 ? 1 : 2;
            $date->add(new DateInterval(sprintf('P%dD', $weekendInterval)));
        }

        /**
         * Since we cannot have a bank holiday on a weekend, but we can have one after,
         * we need to check if we have moved from a weekend to a bank holiday and adjust again.
         */
        if (static::isBankHoliday($date)) {
            $date->add(new DateInterval('P1D'));
        }

        /**
         * Easter and Christmas make it so that we can have 2 bank holidays in a row.
         * We also can go from a bank holiday Friday to a weekend. So we will check
         * again. If we are still on a weekend or a bank holiday,  we need to recurse
         * and adjust again.
         */
        if (static::isWeekendOrBankHoliday($date)) {
            return $this->adjustPaymentForBankHolidayAndWeekend($date);
        }
        return $date;
    }

    /**
     * Cancels a policy.
     * @param Policy   $policy                      The policy to cancel.
     * @param string   $reason                      The reason for cancellation. Must be one of Policy::CANCELLED_*.
     * @param boolean  $closeOpenClaims             Where we are required to cancel the policy (binder), we need to
     *                                              close out claims
     * @param DateTime $date                        The date to say the policy is being cancelled at.
     * @param boolean  $skipUnpaidMinTimeframeCheck Require at least 15 days from last unpaid status change
     * @param boolean  $fullRefund                  Provide a full refund to the customer
     */
    public function cancel(
        Policy $policy,
        $reason,
        $closeOpenClaims = false,
        DateTime $date = null,
        $skipUnpaidMinTimeframeCheck = false,
        $fullRefund = false
    ) {
        if ($reason == Policy::CANCELLED_UNPAID && $policy->getStatus() == Policy::STATUS_UNPAID &&
            !$skipUnpaidMinTimeframeCheck
        ) {
            /** @var LogEntryRepository $logRepo */
            $logRepo = $this->dm->getRepository(LogEntry::class);
            /** @var LogEntry|null $history */
            $history = $logRepo->findRecentStatus($policy);
            $now = $date;
            if (!$now) {
                $now = DateTime::createFromFormat('U', time());
            }
            $loggedAt = DateTime::createFromFormat('U', time());
            if ($history) {
                $loggedAt = $history->getLoggedAt();
            }
            $diff = $now->diff($loggedAt);
            if ($diff->days < 15) {
                // avoid warning every hour; 12 hours is sufficent
                $key = sprintf(self::KEY_PREVENT_CANCELLATION, $policy->getId());
                if (!$this->redis->exists($key)) {
                    $this->redis->setex($key, self::CACHE_PREVENT_CANCELLATION, 1);
                    throw new \Exception(sprintf(
                        'Unable to cancel unpaid policy %s/%s as less than 15 days in unpaid state.',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    ));
                } else {
                    // don't throw exception, but do not process cancellation
                    return;
                }
            }
        }
        if ($closeOpenClaims && $policy->hasOpenClaim()) {
            foreach ($policy->getClaims() as $claim) {
                if ($claim->isOpen()) {
                    $claim->setStatus(Claim::STATUS_PENDING_CLOSED);
                    $this->claimPendingClosedEmail($claim);
                }
            }
            $this->dm->flush();
        }
        $policy->cancel($reason, $date, $fullRefund);
        $this->dm->flush();
        $this->cancelledPolicyEmail($policy);
        $this->cancelledPolicySms($policy);
        if (count($policy->getConnections()) > 0 && $reason == Policy::CANCELLED_UPGRADE) {
            $this->logger->warning(sprintf(
                'Policy %s/%s was cancelled for upgrade. Remember to add connnections to new policy',
                $policy->getPolicyNumber(),
                $policy->getId()
            ));
        }

        $this->dispatchEvent(PolicyEvent::EVENT_CANCELLED, new PolicyEvent($policy));
    }

    public function resendPolicyEmail(Policy $policy)
    {
        $files = [];
        // TODO: Refactor to use getPolicyScheduleFiles & getPolicyTermsFiles
        // make sure we get the most recent version of each file type as there may be more than 1 if regenerated
        foreach ($policy->getPolicyFiles() as $file) {
            $add = false;
            $class = get_class($file);
            if ($file instanceof PolicyScheduleFile || $file instanceof PolicyTermsFile) {
                if (!isset($files[$class])) {
                    $add = true;
                } elseif ($file->getCreated() > $files[$class]->getCreated()) {
                    $add = true;
                }
            }

            if ($add) {
                $files[$class] = $file;
            }
        }

        $attachments = [];
        foreach ($files as $file) {
            $attachments[] = $this->downloadS3($file);
        }

        return $this->newPolicyEmail($policy, $attachments, 'bcc@so-sure.com');
    }

    public function sendBacsPaymentRequest(Policy $policy)
    {
        if (!$this->mailer) {
            return false;
        }

        $baseTemplate = 'AppBundle:Email:bacs/paymentRequest';

        try {
            $this->mailer->sendTemplateToUser(
                'Request for payment on your so-sure policy',
                $policy->getUser(),
                sprintf('%s.html.twig', $baseTemplate),
                ['policy' => $policy],
                sprintf('%s.txt.twig', $baseTemplate),
                ['policy' => $policy]
            );

            $policy->setLastEmailed(DateTime::createFromFormat('U', time()));
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending bacs payment request email to %s', $policy->getUser()->getEmail()),
                ['exception' => $e]
            );
        }

        return true;
    }

    public function downloadS3(S3File $s3file)
    {
        $file = sprintf('%s/%s', sys_get_temp_dir(), $s3file->getFilename());
        if (file_exists($file)) {
            unlink($file);
        }

        $result = $this->s3->getObject(array(
            'Bucket' => $s3file->getBucket(),
            'Key'    => $s3file->getKey(),
            'SaveAs' => $file,
        ));

        return $file;
    }

    /**
     * Sends out the emails for a policy that has been upgraded.
     * @param Policy $policy is the policy that has been upgraded.
     */
    public function upgradedPolicyEmail($policy, $attachmentFiles = null, $bcc = null)
    {
        if (!$this->mailer) {
            return;
        }
        $baseTemplate = 'AppBundle:Email:upgrades/upgradePhone';
        try {
            $this->mailer->sendTemplateToUser(
                sprintf('Your so-sure policy %s has been upgraded', $policy->getPolicyNumber()),
                $policy->getUser(),
                'AppBundle:Email:upgrades/upgradePhone.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:upgrades/upgradePhone.txt.twig',
                ['policy' => $policy],
                $attachmentFiles,
                $bcc
            );
            $policy->setLastEmailed(new DateTime());
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending policy email to %s', $policy->getUser()->getEmail()),
                ['exception' => $e]
            );
            return false;
        }
        return true;
    }

    /**
     * @param Policy $policy
     * @return boolean|null the success of the emailing unless there is no mailer in which case it returns nothing.
     */
    public function newPolicyEmail(Policy $policy, $attachmentFiles = null, $bcc = null)
    {
        if (!$this->mailer) {
            return;
        }

        $baseTemplate = 'AppBundle:Email:policy/new';
        if ($policy->getPreviousPolicy()) {
            $baseTemplate = 'AppBundle:Email:policy/renew';
        }

        try {
            $this->mailer->sendTemplateToUser(
                sprintf('Your so-sure policy %s', $policy->getPolicyNumber()),
                $policy->getUser(),
                sprintf('%s.html.twig', $baseTemplate),
                ['policy' => $policy],
                sprintf('%s.txt.twig', $baseTemplate),
                ['policy' => $policy],
                $attachmentFiles,
                $bcc
            );
            $policy->setLastEmailed(DateTime::createFromFormat('U', time()));
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending policy email to %s', $policy->getUser()->getEmail()),
                ['exception' => $e]
            );

            return false;
        }

        return true;
    }

    /**
     * @param PhonePolicy $policy
     * @param string      $detectedImei
     * @param User        $adminUser
     * @param string      $notes
     */
    public function setDetectedImei(PhonePolicy $policy, $detectedImei, User $adminUser, $notes)
    {
        $policy->setDetectedImei($detectedImei);

        $policy->addNoteDetails(
            $notes,
            $adminUser,
            'Detected IMEI Update'
        );
        $this->dm->flush();

        $this->detectedImeiEmail($policy);
    }

    /**
     * @param PhonePolicy $policy
     * @param boolean     $invalidImei
     * @param User        $adminUser
     * @param string      $notes
     */
    public function setInvalidImei(PhonePolicy $policy, $invalidImei, User $adminUser, $notes)
    {
        $policy->setInvalidImei($invalidImei);

        $policy->addNoteDetails(
            $notes,
            $adminUser,
            'Invalid IMEI Update'
        );
        $this->dm->flush();

        // only email user if its invalid
        if ($invalidImei) {
            $this->invalidImeiEmail($policy);
        }
    }

    /**
     * @param PhonePolicy $policy
     */
    public function detectedImeiEmail(PhonePolicy $policy)
    {
        $baseTemplate = 'AppBundle:Email:policy/detectedImei';

        $this->mailer->sendTemplateToUser(
            sprintf('You can now login to the so-sure app!'),
            $policy->getUser(),
            sprintf('%s.html.twig', $baseTemplate),
            ['policy' => $policy],
            sprintf('%s.txt.twig', $baseTemplate),
            ['policy' => $policy]
        );
    }

    /**
     * @param PhonePolicy $policy
     */
    public function invalidImeiEmail(PhonePolicy $policy)
    {
        $baseTemplate = 'AppBundle:Email:policy/invalidImei';

        $this->mailer->sendTemplateToUser(
            sprintf('Important Information regarding your so-sure Policy %s', $policy->getPolicyNumber()),
            $policy->getUser(),
            sprintf('%s.html.twig', $baseTemplate),
            ['policy' => $policy],
            sprintf('%s.txt.twig', $baseTemplate),
            ['policy' => $policy],
            null,
            'bcc@sosure.com'
        );
    }

    /**
     * @param Policy $policy
     */
    public function cancelledPolicyEmail(Policy $policy, $baseTemplate = null)
    {
        if (!$this->mailer) {
            return;
        }
        if ($policy->getCancelledReason() == Policy::CANCELLED_PICSURE_REQUIRED_EXPIRED) {
            return;
        }
        if (!$baseTemplate) {
            $baseTemplate = sprintf('AppBundle:Email:policy-cancellation/%s', $policy->getCancelledReason());
            if ($policy->isCancelledAndPaymentOwed()) {
                $baseTemplate = sprintf('%sWithClaim', $baseTemplate);
            }
        }
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $this->mailer->sendTemplateToUser(
            sprintf('Your so-sure policy %s is now cancelled', $policy->getPolicyNumber()),
            $policy->getUser(),
            $htmlTemplate,
            ['policy' => $policy],
            $textTemplate,
            ['policy' => $policy],
            null,
            'bcc@so-sure.com'
        );
    }

    /**
     * Sends the owner of given policy an email telling them that they have got a taste card now.
     * @param Policy $policy is the policy that has now had a taste card added.
     */
    public function tasteCardEmail($policy)
    {
        if (!$policy->getTasteCard()) {
            $policyNumber = $policy->getPolicyNumber();
            $this->logger->error("Trying to notify policy {$policyNumber} of nonexistent tastecard.");
        } elseif ($this->mailer) {
            $this->mailer->sendTemplateToUser(
                "Your new Taste Card from So-Sure",
                $policy->getUser(),
                'AppBundle:Email:policy/email_new_taste_card.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:policy/email_new_taste_card.txt.twig',
                ['policy' => $policy],
                null,
                'bcc@so-sure.com'
            );
        }
    }

    /**
     * @param Policy $policy
     */
    public function cancelledPolicySms(Policy $policy)
    {
        if ($this->environment != 'prod') {
            return;
        }

        if (!$policy->isCancelledAndPaymentOwed()) {
            return;
        }

        $smsTemplate = 'AppBundle:Sms:cancelledWithPaymentOwed.txt.twig';
        $this->sms->sendUser($policy, $smsTemplate, ['policy' => $policy]);
    }

    public function claimPendingClosedEmail(Claim $claim)
    {
        if (!$this->mailer) {
            return;
        }

        $baseTemplate = sprintf('AppBundle:Email:claimsHandler/claimCancellation');
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $this->mailer->sendTemplate(
            sprintf('@%s Claim should be closed', $claim->getNumber()),
            $claim->getHandlingTeamEmail(),
            $htmlTemplate,
            ['claim' => $claim],
            null,
            null,
            null,
            'bcc@so-sure.com'
        );
    }

    /**
     * @param Connection $connection
     */
    public function connectionReduced(Connection $connection)
    {
        if (!$this->mailer) {
            return null;
        }

        // Upgrades should not send cancellation emails
        if ($connection->getLinkedPolicy()->isCancelled() &&
            $connection->getLinkedPolicy()->getCancelledReason() == Policy::CANCELLED_UPGRADE) {
            return false;
        }

        // Policy with the reduced connection value
        $policy = $connection->getSourcePolicy();
        // User who caused the reduction
        $causalUser = $connection->getLinkedPolicy()->getUser();
        $this->mailer->sendTemplate(
            sprintf('Important Information about your so-sure Reward Pot'),
            $policy->getUser()->getEmail(),
            'AppBundle:Email:policy/connectionReduction.html.twig',
            ['connection' => $connection, 'policy' => $policy, 'causalUser' => $causalUser],
            'AppBundle:Email:policy/connectionReduction.txt.twig',
            ['connection' => $connection, 'policy' => $policy, 'causalUser' => $causalUser],
            null,
            'bcc@so-sure.com'
        );

        return true;
    }

    /**
     * @param Policy $policy
     */
    public function expiredPolicyEmail(Policy $policy)
    {
        if (!$this->mailer) {
            return;
        }

        if ($policy->isRenewed()) {
            // No need to send an email as the renewal email should cover both expiry and renewal
            return;
        } elseif ($policy->canRepurchase() && $policy->getUser()->areRenewalsDesired()) {
            $baseTemplate = sprintf('AppBundle:Email:policy/expiredDesireRepurchase');
            $subject = sprintf('Your so-sure policy %s is now finished', $policy->getPolicyNumber());
        } else {
            $baseTemplate = sprintf('AppBundle:Email:policy/expired');
            $subject = sprintf('Your so-sure policy %s is now finished', $policy->getPolicyNumber());
        }

        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $this->mailer->sendTemplateToUser(
            $subject,
            $policy->getUser(),
            $htmlTemplate,
            ['policy' => $policy],
            $textTemplate,
            ['policy' => $policy],
            null
        );
    }

    /**
     * @param Policy $policy
     */
    public function skippedRenewalEmail(Policy $policy)
    {
        if (!$this->mailer) {
            return;
        }

        $baseTemplate = sprintf('AppBundle:Email:policy/skippedRenewal');
        $subject = sprintf('Your so-sure policy %s is unable to be automatically renewed', $policy->getPolicyNumber());

        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $this->mailer->sendTemplateToUser(
            $subject,
            $policy->getUser(),
            $htmlTemplate,
            ['policy' => $policy],
            $textTemplate,
            ['policy' => $policy],
            null
        );
    }

    public function getBreakdownData()
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $repo->findAllActiveUnpaidPolicies();
        $phones = [];
        $makes = [];
        foreach ($policies as $policy) {
            if (!isset($phones[$policy->getPhone()->getId()])) {
                $phones[$policy->getPhone()->getId()] = [
                    'phone' => $policy->getPhone()->__toString(),
                    'count' => 0,
                ];
            }
            if (!isset($makes[$policy->getPhone()->getMake()])) {
                $makes[$policy->getPhone()->getMake()] = 0;
            }
            $phones[$policy->getPhone()->getId()]['count']++;
            $makes[$policy->getPhone()->getMake()]++;
        }

        usort($phones, function ($a, $b) {
            if ($a['count'] == $b['count']) {
                return strcmp($a['phone'], $b['phone']);
            }

            return $a['count'] < $b['count'];
        });

        arsort($makes);

        return ['total' => count($policies), 'phones' => $phones, 'makes' => $makes];
    }

    public function getBreakdownPdf($file = null)
    {
        $now = DateTime::createFromFormat('U', time());
        $this->snappyPdf->setOption('orientation', 'Portrait');
        $this->snappyPdf->setOption('page-size', 'A4');
        $html = $this->templating->render('AppBundle:Pdf:policyBreakdown.html.twig', [
            'data' => $this->getBreakdownData(),
            'now' => $now,
        ]);
        $options = [
            'margin-top'    => 20,
            'margin-bottom' => 20,
        ];

        if (!$file) {
            return $this->snappyPdf->getOutputFromHtml($html, $options);
        } else {
            return $this->snappyPdf->generateFromHtml($html, $file);
        }
    }

    public function getPoliciesPendingCancellation($includeFuture = false, DateTime $date = null)
    {
        /** @var PolicyRepository $repo */
        $repo = $this->dm->getRepository(Policy::class);
        return $repo->findPoliciesForPendingCancellation($includeFuture, $date);
    }

    public function getPoliciesForUnRenew(DateTime $date = null)
    {
        /** @var PolicyRepository $repo */
        $repo = $this->dm->getRepository(Policy::class);

        return $repo->findDeclinedRenewalPoliciesForUnRenewed($date);
    }

    public function getPoliciesForRenew(DateTime $date = null)
    {
        /** @var PolicyRepository $repo */
        $repo = $this->dm->getRepository(Policy::class);

        return $repo->findPendingRenewalPoliciesForRenewed($date);
    }

    public function cancelPoliciesPendingCancellation($dryRun = false, DateTime $date = null)
    {
        $cancelled = [];
        $policies = $this->getPoliciesPendingCancellation(false, $date);
        foreach ($policies as $policy) {
            $cancelled[$policy->getId()] = $policy->getPolicyNumber();
            if (!$dryRun) {
                try {
                    $this->cancel($policy, Policy::CANCELLED_USER_REQUESTED, true, $date);
                } catch (\Exception $e) {
                    $msg = sprintf(
                        'Error Cancelling Policy %s / %s',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    );
                    $this->logger->error($msg, ['exception' => $e]);
                }
            }
        }

        return $cancelled;
    }

    public function unrenewPolicies($dryRun = false, DateTime $date = null)
    {
        $expired = [];
        $policies = $this->getPoliciesForUnRenew($date);
        foreach ($policies as $policy) {
            $expired[$policy->getId()] = $policy->getPolicyNumber();
            if (!$dryRun) {
                try {
                    $this->unrenew($policy, $date);
                } catch (\Exception $e) {
                    $msg = sprintf(
                        'Error Un-Renewing Policy %s',
                        $policy->getId()
                    );
                    $this->logger->error($msg, ['exception' => $e]);
                }
            }
        }

        return $expired;
    }

    public function renewPolicies($dryRun = false, DateTime $date = null)
    {
        $renewed = [];
        $policies = $this->getPoliciesForRenew($date);
        foreach ($policies as $policy) {
            $prevPolicy = $policy->getPreviousPolicy();
            $renewed[$prevPolicy->getId()] = $prevPolicy->getPolicyNumber();
            if (!$dryRun) {
                try {
                    $this->autoRenew($prevPolicy, $date);
                } catch (\Exception $e) {
                    $msg = sprintf(
                        'Error Renewing Policy %s',
                        $prevPolicy->getId()
                    );
                    $this->logger->error($msg, ['exception' => $e]);
                }
            }
        }

        return $renewed;
    }

    public function cancelUnpaidPolicies($dryRun = false, $skipUnpaidMinTimeframeCheck = false)
    {
        $cancelled = [];
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findBy(['status' => Policy::STATUS_UNPAID]);
        /** @var Policy $policy */
        foreach ($policies as $policy) {
            try {
                /** @var Policy $policy */
                if ($policy->shouldExpirePolicy() && $policy->shouldCancelPolicy()) {
                    $msg = sprintf(
                        'Skipping Cancelling Policy as it should be expired %s / %s',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    );
                    $this->logger->error($msg);
                } elseif ($policy->shouldCancelPolicy()) {
                    $cancelled[$policy->getId()] = $policy->getPolicyNumber();
                    if (!$dryRun) {
                        $this->cancel($policy, Policy::CANCELLED_UNPAID, true, null, $skipUnpaidMinTimeframeCheck);
                    }
                }
            } catch (\Exception $e) {
                $msg = sprintf(
                    'Error Cancelling Policy %s / %s',
                    $policy->getPolicyNumber(),
                    $policy->getId()
                );
                $this->logger->error($msg, ['exception' => $e]);
            }
        }
        return $cancelled;
    }

    /**
     * Cancels all policies that entered the picsure required stage 14 days ago or more.
     * @param boolean $dry is whether to not really cancel them but just pretend.
     * @return array containing the ids and policy numbers of the cancelled or pretend cancelled policies.
     */
    public function cancelOverduePicsurePolicies($dry)
    {
        /** @var LogEntryRepository $logEntryRepo */
        $logEntryRepo = $this->dm->getRepository(LogEntry::class);
        $cutoffDate = (new DateTime())->sub(new DateInterval("P14D"));
        $cancelled = [];
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findBy(['status' => Policy::STATUS_PICSURE_REQUIRED]);
        foreach ($policies as $policy) {
            /** @var LogEntry|null $history */
            $history = $logEntryRepo->findRecentStatus($policy);
            if (!$history) {
                $this->logger->error(sprintf(
                    "Policy '%s' is in picsure-required status, but there is no relevant log entry",
                    $policy->getId()
                ));
                continue;
            }
            if ($history->getLoggedAt() <= $cutoffDate) {
                $cancelled[$policy->getId()] = $policy->getPolicyNumber();
                if (!$dry) {
                    $this->cancel($policy, Policy::CANCELLED_PICSURE_REQUIRED_EXPIRED, true);
                }
            }
        }
        return $cancelled;
    }

    public function activateRenewalPolicies($dryRun = false, DateTime $date = null)
    {
        $renewals = [];
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findRenewalPoliciesForActivation();
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            $renewals[$policy->getId()] = $policy->getPolicyNumber();
            if (!$dryRun) {
                try {
                    $this->activate($policy, $date);
                } catch (\Exception $e) {
                    $msg = sprintf(
                        'Error activating Policy %s / %s',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    );
                    $this->logger->error($msg, ['exception' => $e]);
                }
            }
        }

        return $renewals;
    }

    public function expireEndingPolicies($dryRun = false, DateTime $date = null)
    {
        $expired = [];
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findPoliciesForExpiration($date);
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            $expired[$policy->getId()] = $policy->getPolicyNumber();
            if (!$dryRun) {
                try {
                    $this->expire($policy);
                } catch (\Exception $e) {
                    $msg = sprintf(
                        'Error Expiring Policy %s / %s',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    );
                    $this->logger->error($msg, ['exception' => $e]);
                }
            }
        }

        return $expired;
    }

    public function setUnpaidForCancelledMandate($dryRun = false, DateTime $date = null)
    {
        if (!$date) {
            $date = DateTime::createFromFormat('U', time());
        }
        $unpaid = [];
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findUnpaidPoliciesWithCancelledMandates();
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            if ($policy->isPolicyPaidToDate($date, true, false, true)) {
                continue;
            }

            $unpaid[$policy->getId()] = $policy->getPolicyNumber();
            if (!$dryRun) {
                try {
                    $policy->setStatus(Policy::STATUS_UNPAID);
                    $this->dm->flush();
                } catch (\Exception $e) {
                    $msg = sprintf(
                        'Error setting policy to unpaid %s / %s',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    );
                    $this->logger->error($msg, ['exception' => $e]);
                }
            }
        }

        return $unpaid;
    }

    public function fullyExpireExpiredClaimablePolicies($dryRun = false, DateTime $date = null)
    {
        if (!$date) {
            $date = DateTime::createFromFormat('U', time());
        }
        $fullyExpired = [];
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findPoliciesForFullExpiration();
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            $fullyExpired[$policy->getId()] = $policy->getPolicyNumber();
            if (!$dryRun) {
                try {
                    $result = $this->fullyExpire($policy, $date);
                    if ($result === null) {
                        $skipLogging = true;
                        foreach ($policy->getClaims() as $claim) {
                            if (!$claim->isIgnoreWarningFlagSet(
                                Claim::WARNING_FLAG_IGNORE_POLICY_EXPIRE_CLAIM_WAIT
                            )) {
                                $skipLogging = false;
                            }
                        }

                        // avoid sending constantly for the same claims, but at least send once a day
                        if (!$skipLogging || $date->format('H') == 9) {
                            $fullyExpired[$policy->getId()] = sprintf(
                                '%s - waiting on claim',
                                $policy->getPolicyNumber()
                            );
                        } else {
                            //print 'unset' . PHP_EOL;
                            unset($fullyExpired[$policy->getId()]);
                        }
                    }
                } catch (\Exception $e) {
                    $msg = sprintf(
                        'Error Fully Expiring Policy %s / %s',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    );
                    $this->logger->error($msg, ['exception' => $e]);
                }
            }
        }

        return $fullyExpired;
    }

    public function runMetrics($dryRun)
    {
        $lines = [];
        /** @var PhonePolicyRepository $phonePolicyRepo */
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);

        $activationDate = DateTime::createFromFormat('U', time());
        $activationDate = $activationDate->sub(SoSure::getActivationInterval());
        $hardActivationDate = DateTime::createFromFormat('U', time());
        $hardActivationDate = $hardActivationDate->sub(SoSure::getHardActivationInterval());

        $metrics = [
            Policy::METRIC_ACTIVATION => $activationDate,
            Policy::METRIC_HARD_ACTIVATION => $hardActivationDate,
        ];

        foreach ($metrics as $metric => $date) {
            $policies = $phonePolicyRepo->findAllActiveUnpaidPolicies(null, $date, $metric);
            foreach ($policies as $policy) {
                /** @var Policy $policy */
                if (isset($lines[$policy->getId()])) {
                    $lines[$policy->getId()] = sprintf("%s, %s", $lines[$policy->getId()], $metric);
                } else {
                    $lines[$policy->getId()] = sprintf("%s: %s", $policy->getPolicyNumber(), $metric);
                }
                if (!$dryRun) {
                    $policy->addMetric($metric);
                }
            }
        }
        if (!$dryRun) {
            $this->dm->flush();
        }

        return $lines;
    }

    public function cashbackMissingReminder($dryRun)
    {
        $now = DateTime::createFromFormat('U', time());
        $cashback = [];
        /** @var CashbackRepository $cashbackRepo */
        $cashbackRepo = $this->dm->getRepository(Cashback::class);
        $cashbackItems = $cashbackRepo->findBy(['status' => Cashback::STATUS_MISSING]);
        foreach ($cashbackItems as $cashbackItem) {
            /** @var Cashback $cashbackItem */
            $cashback[$cashbackItem->getId()] = $cashbackItem->getPolicy()->getPolicyNumber();
            if (!$dryRun) {
                try {
                    $diff = $now->diff($cashbackItem->getDate());
                    if ($diff->days < 90) {
                        $this->cashbackEmail($cashbackItem);
                    } else {
                        $this->mailer->sendTemplate(
                            sprintf('Unclaimed cashback %s', $cashbackItem->getPolicy()->getPolicyNumber()),
                            'tech+ops@so-sure.com',
                            'AppBundle:Email:cashback/admin_missing.html.twig',
                            ['cashback' => $cashbackItem]
                        );
                    }
                } catch (\Exception $e) {
                    $msg = sprintf(
                        'Error Cashback Reminder %s / %s',
                        $cashbackItem->getPolicy()->getPolicyNumber(),
                        $cashbackItem->getId()
                    );
                    $this->logger->error($msg, ['exception' => $e]);
                }
            }
        }

        return $cashback;
    }

    public function cashbackPendingReminder($dryRun)
    {
        /** @var CashbackRepository $cashbackRepo */
        $cashbackRepo = $this->dm->getRepository(Cashback::class);
        $cashbacks = $cashbackRepo->findBy(['status' => Cashback::STATUS_PENDING_PAYMENT]);

        if (!$dryRun) {
            $this->mailer->sendTemplate(
                'Biweekly cashback report',
                ['dylan@so-sure.com', 'julien@so-sure.com'],
                'AppBundle:Email:cashback/cashback_reminder.html.twig',
                ['cashbacks' => $cashbacks]
            );
        }
        $ids = [];
        foreach ($cashbacks as $cashback) {
            $ids[$cashback->getId()] = $cashback->getPolicy()->getPolicyNumber();
        }
        return $ids;
    }

    /**
     * @param Policy   $policy
     * @param DateTime $date
     */
    public function expire(Policy $policy, DateTime $date = null)
    {
        $policy->expire($date);
        // TODO: consider if we need to handle the pending renewal cancellation here at the same time
        // to avoid any timing issues
        $this->dm->flush();

        $this->expiredPolicyEmail($policy);

        $this->dispatchEvent(PolicyEvent::EVENT_EXPIRED, new PolicyEvent($policy));
    }

    /**
     * @param Policy   $policy
     * @param DateTime $date
     */
    public function fullyExpire(Policy $policy, DateTime $date = null)
    {
        $initialStatus = $policy->getStatus();
        $policy->fullyExpire($date);
        $this->dm->flush();

        // If no change in wait claim, then don't proceed as may resend emails for cashback
        if ($policy->getStatus() == Policy::STATUS_EXPIRED_WAIT_CLAIM &&
            $initialStatus == Policy::STATUS_EXPIRED_WAIT_CLAIM) {
            return null;
        }

        if ($policy->hasCashback() && $policy->getCashback() && !in_array($policy->getCashback()->getStatus(), [
                Cashback::STATUS_MISSING,
                Cashback::STATUS_FAILED,
                Cashback::STATUS_PAID,
            ])) {
            if ($policy->getStatus() == Policy::STATUS_EXPIRED_WAIT_CLAIM) {
                $this->updateCashback($policy->getCashback(), Cashback::STATUS_PENDING_WAIT_CLAIM);
            } elseif ($this->areEqualToTwoDp(0, $policy->getCashback()->getAmount())) {
                $this->updateCashback($policy->getCashback(), Cashback::STATUS_CLAIMED);
            } else {
                $this->updateCashback($policy->getCashback(), Cashback::STATUS_PENDING_PAYMENT);
            }
        }

        if ($policy->isRenewed() && $policy->hasAdjustedRewardPotPayment()) {
            $outstanding = $policy->getNextPolicy()->getOutstandingPremiumToDate(
                $date ? $date : DateTime::createFromFormat('U', time()),
                true
            );
            $this->regenerateScheduledPayments($policy->getNextPolicy(), $date, $date, null, $outstanding);

            // bill for outstanding payments due
            $outstanding = $policy->getNextPolicy()->getOutstandingUserPremiumToDate(
                $date ? $date : DateTime::createFromFormat('U', time())
            );
            if ($policy->hasCheckoutPaymentMethod()) {
                $scheduledPayment = new ScheduledPayment();
                $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
                $scheduledPayment->setScheduled($date ? $date : DateTime::createFromFormat('U', time()));
                $scheduledPayment->setAmount($outstanding);
                $scheduledPayment->setNotes(sprintf(
                    'Claw-back applied discount (discount was removed following success claim for previous policy)'
                ));
                $policy->getNextPolicy()->addScheduledPayment($scheduledPayment);
            } else {
                $this->logger->warning(sprintf(
                    'Failed to schedule claw back discount for policy %s as on bacs. Owed %0.2f',
                    $policy->getId(),
                    $outstanding
                ));
            }
            $this->dm->flush();
            //\Doctrine\Common\Util\Debug::dump($scheduledPayment);

            $this->adjustPotRewardEmail($policy->getNextPolicy(), $outstanding);
        }

        return true;
    }

    public function adjustPotRewardEmail(Policy $policy, $additionalAmount)
    {
        if (!$this->mailer) {
            return;
        }

        $baseTemplate = sprintf('AppBundle:Email:potReward/adjusted');
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $subject = sprintf('Important information about your Reward Pot');
        $this->mailer->sendTemplateToUser(
            $subject,
            $policy->getUser(),
            $htmlTemplate,
            ['policy' => $policy, 'additional_amount' => $additionalAmount],
            $textTemplate,
            ['policy' => $policy, 'additional_amount' => $additionalAmount],
            null,
            'bcc@so-sure.com'
        );
    }

    public function cashback(Policy $policy, Cashback $cashback)
    {
        // TODO: Validate cashback amount
        $policy->setCashback($cashback);
        $this->dm->flush();

        if ($cashback->getAmount()) {
            // cashback needs id, so flush is required above
            $this->updateCashback($cashback, $cashback->getExpectedStatus());
            $this->dm->flush();
        }

        $this->dispatchEvent(PolicyEvent::EVENT_CASHBACK, new PolicyEvent($policy));
    }

    /**
     * @param Policy        $policy
     * @param DateTime|null $date
     * @throws \Exception
     */
    public function activate(Policy $policy, DateTime $date = null)
    {
        $policy->activate($date);
        $this->dm->flush();

        $this->dispatchEvent(PolicyEvent::EVENT_START, new PolicyEvent($policy));

        // Not necessary to email as already received docs at time of renewal
    }

    /**
     * @param Policy $policy
     */
    public function unrenew(Policy $policy, DateTime $date = null)
    {
        $policy->unrenew($date);
        $this->dm->flush();

        // Might be an idea to email at this point,
        // although the policy end status is probably set at the same time
    }

    public function createPendingRenewalPolicies($dryRun = false, DateTime $date = null)
    {
        $pendingRenewal = [];
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findPoliciesForPendingRenewal($date);
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            if ($policy->canCreatePendingRenewal($date)) {
                $pendingRenewal[$policy->getId()] = $policy->getPolicyNumber();
                if (!$dryRun) {
                    try {
                        $this->createPendingRenewal($policy, $date);
                    } catch (\Exception $e) {
                        $msg = sprintf(
                            'Error creating pending renewal Policy %s / %s',
                            $policy->getPolicyNumber(),
                            $policy->getId()
                        );
                        $this->logger->error($msg, ['exception' => $e]);
                    }
                }
            }
        }

        return $pendingRenewal;
    }

    public function notifyPendingCancellations($days = null)
    {
        $count = 0;
        if (!$days) {
            $days = 5;
        }
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(Policy::class);
        $date = DateTime::createFromFormat('U', time());
        $date = $date->add(new DateInterval(sprintf('P%dD', $days)));

        $pendingCancellationPolicies = $policyRepo->findPoliciesForPendingCancellation(false, $date);
        foreach ($pendingCancellationPolicies as $policy) {
            /** @var Policy $policy */
            if ($policy->hasOpenClaim()) {
                foreach ($policy->getClaims() as $claim) {
                    if ($claim->isOpen()) {
                        $this->pendingCancellationEmail($claim, $policy->getPendingCancellation());
                        $count++;
                    }
                }
            }
        }

        $policies = $policyRepo->findBy(['status' => Policy::STATUS_UNPAID]);
        foreach ($policies as $policy) {
            if ($policy->shouldCancelPolicy($date) && $policy->hasOpenClaim()) {
                foreach ($policy->getClaims() as $claim) {
                    /** @var Policy $policy */
                    if ($claim->isOpen()) {
                        $this->pendingCancellationEmail($claim, $policy->getPolicyExpirationDate());
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    public function pendingCancellationEmail(Claim $claim, $cancellationDate)
    {
        if (!$this->mailer) {
            return;
        }

        $baseTemplate = sprintf('AppBundle:Email:claimsHandler/pendingCancellation');
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $subject = sprintf(
            '@%s Claim should be finalised',
            $claim->getNumber()
        );

        $this->mailer->sendTemplate(
            $subject,
            $claim->getHandlingTeamEmail(),
            $htmlTemplate,
            ['claim' => $claim, 'cancellationDate' => $cancellationDate],
            null,
            null,
            null,
            'bcc@so-sure.com'
        );
    }

    /**
     * @param Policy        $policy
     * @param DateTime|null $date
     * @return Policy
     * @throws \Exception
     */
    public function createPendingRenewal(Policy $policy, DateTime $date = null)
    {
        $date = $date ?: new DateTime();
        /** @var PolicyTermsRepository $policyTermsRepo */
        $policyTermsRepo = $this->dm->getRepository(PolicyTerms::class);
        /** @var PolicyTerms $latestTerms */
        $latestTerms = $policyTermsRepo->findLatestTerms();
        $newPolicy = $policy->createPendingRenewal($latestTerms, $date);
        if ($policy->getCompany()) {
            $this->priceService->setPhonePolicyPremium(
                $newPolicy,
                PhonePrice::installmentsStream($newPolicy->getPremiumInstallments()),
                $policy->getUser()->getAdditionalPremium(),
                $date
            );
        } else {
            $this->priceService->setPhonePolicyRenewalPremium(
                $newPolicy,
                $policy->getUser()->getAdditionalPremium(),
                $date
            );
        }
        $this->dm->persist($newPolicy);
        $this->dm->flush();

        $this->pendingRenewalEmail($policy);

        /** @var User $user */
        $user = $policy->getUser();
        $isCorporate = $user->getCompany();

        if ($isCorporate !== null) {
            $this->pendingRenewalSoSureEmployeeInvoiceNotification($policy);
        }

        $this->dispatchEvent(PolicyEvent::EVENT_PENDING_RENEWAL, new PolicyEvent($policy));

        return $newPolicy;
    }

    /**
     * @param Policy $policy
     */
    public function pendingRenewalSoSureEmployeeInvoiceNotification(Policy $policy)
    {
        if (!$this->mailer) {
            return;
        }

        $baseTemplate = sprintf('AppBundle:Email:policy/pendingRenewalSoSureEmployeeInvoiceNotification');
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);

        $subject = sprintf(
            'Please Invoice For Corporate Policy Renewal.'
        );
        $data = [
            'policy' => $policy,
            'renew_url' => $this->routerService->generateUrl(
                'user_renew_policy',
                ['id' => $policy->getId()]
            ),
            'start_date' => $this->endOfDay($policy->getEnd()),
        ];
        $this->mailer->sendTemplate(
            $subject,
            SoSure::SOSURE_EMPLOYEE_SALES_EMAIL,
            $htmlTemplate,
            $data
        );
    }

    public function pendingRenewalEmail(Policy $policy)
    {
        if (!$this->mailer) {
            return;
        }

        $baseTemplate = sprintf('AppBundle:Email:policy/pendingRenewal');
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $subject = sprintf(
            'Your so-sure insurance renewal'
        );
        $data = [
            'policy' => $policy,
            'renew_url' => $this->routerService->generateUrl(
                'user_renew_policy',
                ['id' => $policy->getId()]
            ),
            'start_date' => $this->endOfDay($policy->getEnd()),
        ];
        $this->mailer->sendTemplateToUser(
            $subject,
            $policy->getUser(),
            $htmlTemplate,
            $data,
            $textTemplate,
            $data
        );
    }

    public function autoRenew(Policy $policy, DateTime $date = null)
    {
        if ($policy->getStatus() == Policy::STATUS_CANCELLED) {
            $this->logger->error(sprintf(
                'Skipping renewal as policy %s/%s is cancelled',
                $policy->getId(),
                $policy->getPolicyNumber()
            ));
            $policy->getNextPolicy()->setStatus(Policy::STATUS_UNRENEWED);
            $this->dm->flush();
            $this->skippedRenewalEmail($policy);
            return false;
        } else {
            return $this->renew($policy, $policy->getPremiumInstallmentCount(), null, true, $date);
        }
    }

    public function renew(
        Policy $policy,
        $numPayments,
        Cashback $cashback = null,
        $autoRenew = false,
        DateTime $date = null
    ) {
        if (!$date) {
            $date = DateTime::createFromFormat('U', time());
        }

        $newPolicy = $policy->getNextPolicy();
        if (!$newPolicy) {
            throw new \Exception(sprintf(
                'Policy %s does not have a next policy (renewal not allowed)',
                $policy->getId()
            ));
        }

        if (!$newPolicy->isRenewalAllowed($autoRenew, $date)) {
            throw new \Exception(sprintf(
                'Unable to renew policy %s (pending %s) as status is incorrect or its too late',
                $policy->getId(),
                $newPolicy->getId()
            ));
        }

        if ($cashback) {
            $this->cashback($policy, $cashback);
        } else {
            $policy->clearCashback();
        }

        // Until Policy::ADJUST_TIMEZONE is true, ensure we have correct timezone set
        $endDate = clone $policy->getEnd();
        $endDate = $endDate->setTimezone(new \DateTimeZone(Policy::TIMEZONE));
        $startDate = $this->endOfDay($endDate);
        //$startDate = $this->endOfDay($policy->getEnd());
        $discount = 0;
        if (!$cashback && $policy->getPotValue() > 0) {
            // for open claims, we should assume that they are going to be settled, so don't provide a discount
            if (!$policy->hasOpenClaim() && !$policy->hasOpenNetworkClaim()) {
                $discount = $policy->getPotValue();
            }
        }

        if ($payer = $policy->getPayer()) {
            $payer->addPayerPolicy($newPolicy);
        }

        $billing = $startDate;
        if ($policy->getBilling()) {
            $billing = new DateTime(
                sprintf(
                    "%s-%s-%s",
                    $startDate->format("Y"),
                    $startDate->format("m"),
                    $policy->getBilling()->format("d")
                )
            );
        }
        $this->create(
            $newPolicy,
            $startDate,
            false,
            $numPayments,
            $policy->getIdentityLog(),
            $billing
        );
        if (!$newPolicy->renew($discount, $autoRenew, $date)) {
            return null;
        }

        /**
         * For scheduling, there have been times when the renewal scheduled payments
         * are incorrect, and part of that is due to not using the billing date,
         * and part of it is because the payment method is not always set.
         * Right here we will check if the payment method exists, and if not
         * we will set it using the payment method on the previous policy.
         * We cannot do this any earlier in the renewal in case the payment
         * method changes.
         */
        if (!$newPolicy->hasPaymentMethod() && $policy->getPaymentMethod()) {
            $newPolicy->setPaymentMethod(clone $policy->getPaymentMethod());
        }

        $this->generateScheduledPayments($newPolicy, $billing, $date, $numPayments, null, true);

        $policy->addMetric(Policy::METRIC_RENEWAL);

        $this->dm->flush();

        $this->dispatchEvent(PolicyEvent::EVENT_RENEWED, new PolicyEvent($policy));

        return $newPolicy;
    }

    public function declineRenew(Policy $policy, Cashback $cashback = null, DateTime $date = null)
    {
        if (!$date) {
            $date = DateTime::createFromFormat('U', time());
        }

        $newPolicy = $policy->getNextPolicy();
        if (!$newPolicy) {
            throw new \Exception(sprintf(
                'Policy %s does not have a next policy (decline renewal not allowed)',
                $policy->getId()
            ));
        }

        if ($cashback) {
            $this->cashback($policy, $cashback);
        } else {
            $policy->clearCashback();
        }

        $newPolicy->declineRenew($date);
        $this->dm->flush();

        $this->dispatchEvent(PolicyEvent::EVENT_DECLINED_RENEWAL, new PolicyEvent($policy));

        return $newPolicy;
    }

    public function repurchase(Policy $policy, DateTime $date = null)
    {
        if (!$date) {
            $date = DateTime::createFromFormat('U', time());
        }

        if (!$policy->canRepurchase()) {
            throw new \Exception(sprintf(
                'Unable to repurchase policy %s',
                $policy->getId()
            ));
        }

        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $policy;
        $policies = $repo->findDuplicateImei($phonePolicy->getImei());
        /** @var Policy $checkPolicy */
        foreach ($policies as $checkPolicy) {
            /** @var Policy $checkPolicy */
            if (!$checkPolicy->getStatus() &&
                $checkPolicy->getUser()->getId() == $policy->getUser()->getId()) {
                return $checkPolicy;
            }
        }

        $policyTermsRepo = $this->dm->getRepository(PolicyTerms::class);
        /** @var PolicyTerms $latestTerms */
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);
        $newPolicy = $policy->createRepurchase($latestTerms, $date);

        $this->dm->persist($newPolicy);
        $this->dm->flush();

        return $newPolicy;
    }

    public function billingDay(Policy $policy, $day)
    {
        // @codingStandardsIgnoreStart
        $body = sprintf(
            "Policy: <a href='%s'>%s/%s</a> has requested a billing date change to the %d. Verify policy id match in system.",
            $this->routerService->generateUrl(
                'admin_policy',
                ['id' => $policy->getId()]
            ),
            $policy->getPolicyNumber(),
            $policy->getId(),
            $day
        );
        // @codingStandardsIgnoreEnd

        $subject = sprintf(
            'Billing day change request from %s',
            $policy->getPolicyNumber()
        );
        $this->mailer->send($subject, 'contact-us@wearesosure.com', $body);

        $this->intercom->queueMessage($policy->getUser()->getEmail(), $body);
    }

    public function updateCashback(Cashback $cashback, $status)
    {
        if (!$cashback->getAmount()) {
            throw new \Exception(sprintf(
                'Missing cashback amount id %s',
                $cashback->getId()
            ));
        }

        // If no change in status, don't change anything to avoid db logging updates & duplicate emails
        if ($cashback->getStatus() == $status) {
            return;
        }

        $cashback->setDate(DateTime::createFromFormat('U', time()));
        $cashback->setStatus($status);
        $this->dm->flush();

        if (in_array($status, [
            Cashback::STATUS_PAID,
            Cashback::STATUS_FAILED,
            Cashback::STATUS_MISSING,
            Cashback::STATUS_CLAIMED,
            Cashback::STATUS_PENDING_PAYMENT,
            Cashback::STATUS_PENDING_WAIT_CLAIM,
        ])) {
            $this->cashbackEmail($cashback);
        }
    }

    public function cashbackEmail(Cashback $cashback)
    {
        if (!$this->mailer) {
            return;
        }

        if ($cashback->getStatus() == Cashback::STATUS_PAID) {
            $baseTemplate = sprintf('AppBundle:Email:cashback/paid');
            $subject = sprintf('Your Reward Pot has been paid out');
        } elseif ($cashback->getStatus() == Cashback::STATUS_FAILED) {
            $baseTemplate = sprintf('AppBundle:Email:cashback/failed');
            $subject = sprintf('Your SO-SURE cashback');
        } elseif ($cashback->getStatus() == Cashback::STATUS_MISSING) {
            $baseTemplate = sprintf('AppBundle:Email:cashback/missing');
            $subject = sprintf('Your SO-SURE cashback');
        } elseif ($cashback->getStatus() == Cashback::STATUS_PENDING_PAYMENT && !$cashback->isAmountReduced()) {
            $baseTemplate = sprintf('AppBundle:Email:cashback/approved');
            $subject = sprintf('Keeping your phone safe does pay off');
        } elseif ($cashback->getStatus() == Cashback::STATUS_PENDING_PAYMENT && $cashback->isAmountReduced()) {
            $baseTemplate = sprintf('AppBundle:Email:cashback/approved-reduced');
            $subject = sprintf('Important information about your Reward Pot cashback');
        } elseif ($cashback->getStatus() == Cashback::STATUS_CLAIMED) {
            $baseTemplate = sprintf('AppBundle:Email:cashback/claimed');
            $subject = sprintf('Important information about your Reward Pot cashback');
        } elseif ($cashback->getStatus() == Cashback::STATUS_PENDING_WAIT_CLAIM) {
            $baseTemplate = sprintf('AppBundle:Email:cashback/delay');
            $subject = sprintf('Important information about your Reward Pot cashback');
        } else {
            throw new \Exception('Unknown cashback status for email');
        }
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $data = [
            'cashback' => $cashback,
            'withdraw_url' => $this->routerService->generateUrl(
                'user_cashback',
                ['id' => $cashback->getId()]
            ),
        ];
        $this->mailer->sendTemplateToUser(
            $subject,
            $cashback->getPolicy()->getUser(),
            $htmlTemplate,
            $data,
            $textTemplate,
            $data
        );
    }

    /**
     * Enters a policy into a promotion if they are not already participating in it. If they are already participating
     * then it does nothing. It persists the new participation but it does not flush the database.
     * @param Policy        $policy    is the policy to enter in the promotion.
     * @param Promotion     $promotion is the promotion to enter the policy into.
     * @param DateTime|null $date      is the date to set the participation as having started at.
     * @return Participation the new particpation that was created.
     */
    public function enterPromotion(Policy $policy, Promotion $promotion, $date = null)
    {
        $participationRepository = $this->dm->getRepository(Participation::class);
        // NOTE: according to this logic if a policy is entered into a promotion it can never be entered into it again.
        //       if that changes then a check for active participations only can be added here.
        $participation = $participationRepository->findOneBy(["policy" => $policy, "promotion" => $promotion]);
        if ($participation) {
            throw new AlreadyParticipatingException(
                $policy->getPolicyNumber()." is already participating in ".$promotion->getName()
            );
        }
        $date = $date ? clone $date : new DateTime();
        $participation = new Participation();
        $promotion->addParticipating($participation);
        $policy->addParticipation($participation);
        $participation->setStart($date);
        $participation->setStatus(Participation::STATUS_ACTIVE);
        $this->dm->persist($participation);
        return $participation;
    }

    /**
     * Calculates the amount of money owed by an unpaid policy. If it has a normal owed amount that is returned, but if
     * it has no owed calculation but some rescheduled payments it returns the sum of them, and if there is really no
     * source of owed money it sets the policy from unpaid to active.
     * @param Policy   $policy is the policy to check.
     * @param DateTime $date   is the date at which the amount is owed.
     * @return float the owed amount.
     */
    public function checkOwedPremium(Policy $policy, DateTime $date)
    {
        if ($policy->getStatus() != Policy::STATUS_UNPAID) {
            return 0;
        }
        $amount = $policy->getOutstandingPremiumToDate($date);
        if ($this->greaterThanZero($amount)) {
            return $amount;
        }
        /** @var ScheduledPaymentRepository $scheduledPaymentRepo */
        $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
        $rescheduledAmount = $scheduledPaymentRepo->getRescheduledAmount($policy);
        if (!$this->greaterThanZero($rescheduledAmount)) {
            $this->logger->error(sprintf(
                'Policy %s has unpaid status, but paid to date. Setting to active.',
                $policy->getId()
            ));
            $policy->setStatus(Policy::STATUS_ACTIVE);
            $this->dm->flush();
        }
        return $rescheduledAmount;
    }

    /**
     * Applys a referral bonus to the given policy if possible.
     * If it is monthly delete a scheduled payment, and if they are yearly refund a month's worth.
     * Also apparently we are supposed to ignore that there is an existing discount on yearly.
     * @param Policy $policy is the policy to refund.
     */
    public function applyReferralBonus($policy)
    {
        $bonusPayment = new SoSurePayment();
        if ($policy->getPremiumInstallments() == 12) {
            $bonusPayment->setAmount($policy->getUpgradedStandardMonthlyPrice());
        } else {
            $bonusPayment->setAmount($this->toTwoDp($policy->getUpgradedYearlyPrice() / 11));
        }
        $bonusPayment->setPolicy($policy);
        $bonusPayment->calculateSplit();
        if ($policy instanceof SalvaPhonePolicy) {
            $bonusPayment->setCoverholderCommission(Salva::MONTHLY_COVERHOLDER_COMMISSION);
            $bonusPayment->setBrokerCommission(Salva::MONTHLY_BROKER_COMMISSION);
            $bonusPayment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        } else {
            $policy->setCommission($bonusPayment);
        }
        $bonusPayment->setSuccess(true);
        $bonusPayment->setNotes('Referral Bonus');
        if ($policy->getPremiumInstallments() == 1) {
            $paymentMethod = $policy->getPaymentMethod();
            if ($paymentMethod->getType() == PaymentMethod::TYPE_CHECKOUT) {
                /** @var CheckoutPayment $refundPayment */
                $refundPayment = $policy->findPaymentForRefund($bonusPayment->getAmount());
                if ($refundPayment) {
                    $this->checkoutService->refund(
                        $refundPayment,
                        $bonusPayment->getAmount(),
                        $bonusPayment->getCoverholderCommission(),
                        $bonusPayment->getBrokerCommission(),
                        "Referral Bonus"
                    );
                    $bonusPayment->setNotes('Referral Refund');
                    $policy->addPayment($bonusPayment);
                    $this->dm->persist($bonusPayment);
                    $this->dm->flush();
                } else {
                    $this->logger->error(sprintf(
                        'Unable to find refundable payment for referral bonus on policy %s',
                        $policy->getId()
                    ));
                }
            } elseif ($paymentMethod->getType() == PaymentMethod::TYPE_BACS) {
                $scheduledPayment = new ScheduledPayment();
                $scheduledPayment->setPolicy($policy);
                $scheduledPayment->setAmount(0 - $bonusPayment->getAmount());
                $scheduledPayment->setType(ScheduledPayment::TYPE_USER_WEB);
                $scheduledPayment->setNotes('Referral Bonus');
                $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
                $policy->addScheduledPayment($scheduledPayment);
                $policy->addPayment($bonusPayment);
                $this->dm->persist($scheduledPayment);
                $this->dm->persist($bonusPayment);
                $this->dm->flush();
            } else {
                $this->logger->error(sprintf(
                    'Policy %s has invalid payment method',
                    $policy->getId()
                ));
            }
        } else {
            foreach ($policy->getScheduledPayments() as $scheduledPayment) {
                if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED) {
                    $scheduledPayment->cancel("Referral Bonus");
                    $policy->addPayment($bonusPayment);
                    $this->dm->persist($bonusPayment);
                    $this->dm->flush();
                    return;
                }
            }
            $this->logger->error(sprintf(
                'Could not find scheduled payment to skip for policy %s referral bonus',
                $policy->getId()
            ));
        }
    }

    /**
     * Removes all rescheduled payments for the given policy.
     * @param Policy $policy is the policy for whom there shall be no rescheduled payments.
     */
    public function clearRescheduledPayments($policy)
    {
        $scheduledPayments = $policy->getScheduledPayments();
        foreach ($scheduledPayments as $scheduled) {
            if ($scheduled->getType() == ScheduledPayment::TYPE_RESCHEDULED &&
                $scheduled->getStatus() == ScheduledPayment::STATUS_SCHEDULED
            ) {
                $scheduled->cancel('clearing rescheduled payments');
            }
        }
        $this->dm->flush();
    }

    /**
     * Changes a policy's premium installments to the given number (1 or 12 not something weird), and regenerates
     * the schedule. When a policy goes from monthly to yearly this just involves cancelling all scheduled payments and
     * adding one big one with everything they owe. When this is going from yearly to monthly we schedule a refund of
     * the value of all upcoming monthly scheduled payments then create those.
     * @param Policy $policy       is the policy to do the changing to.
     * @param int    $installments is the number of installments to give it.
     */
    public function changeInstallments($policy, $installments)
    {
        if ($policy->getPendingBacsPaymentsTotal(true) > 0) {
            throw new \RuntimeException(sprintf(
                'policy %s cannot change installments while there are pending bacs payments',
                $policy->getId()
            ));
        }
        if ($policy->getPremiumInstallments() == $installments) {
            return;
        }
        $policy->setPremiumInstallments($installments);
        foreach ($policy->getScheduledPayments() as $scheduled) {
            if ($scheduled->getStatus() == ScheduledPayment::STATUS_SCHEDULED) {
                $scheduled->cancel('Updating Premium Installments');
            }
        }
        $this->dm->flush();
        if ($installments == 1) {
            $outstanding = $policy->getOutstandingPremium();
            if ($outstanding != 0) {
                $this->schedulePayment($policy, $policy->getOutstandingPremium());
            }
        } else {
            $upcomingPayments = $policy->getInvoiceSchedule(new \DateTime());
            if ($policy->isPolicyPaidToDate()) {
                $this->schedulePayment(
                    $policy,
                    0 - count($upcomingPayments) * $policy->getUpgradedStandardMonthlyPrice()
                );
            }
            foreach ($upcomingPayments as $upcoming) {
                $this->schedulePayment($policy, $policy->getUpgradedStandardMonthlyPrice(), $upcoming);
            }
        }
        $this->dm->flush();
    }

    /**
     * Creates a scheduled payment. If the amount is negative it automatically sets the type as a refund.
     * @param Policy         $policy is the policy that the scheduled payment is for.
     * @param number         $amount is the amount the scheduled payment is for.
     * @param \DateTime|null $date   is the date that the scheduled payment is for which defaults to right now.
     * @return ScheduledPayment the scheduled payment so you can do things to it.
     */
    public function schedulePayment($policy, $amount, $date = null)
    {
        $date = $date ?: new \DateTime();
        $scheduled = new ScheduledPayment();
        $scheduled->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduled->setScheduled($date);
        $scheduled->setAmount($amount);
        if ($amount < 0) {
            $scheduled->setType(ScheduledPayment::TYPE_REFUND);
        }
        $policy->addScheduledPayment($scheduled);
        $this->dm->persist($scheduled);
        return $scheduled;
    }
}
