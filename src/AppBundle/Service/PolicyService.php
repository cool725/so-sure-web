<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use AppBundle\Document\Cashback;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\SCode;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\File\PolicyTermsFile;
use AppBundle\Document\File\PolicyScheduleFile;
use AppBundle\Document\File\S3File;

use AppBundle\Service\SalvaExportService;
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

use Gedmo\Loggable\Document\LogEntry;

class PolicyService
{
    use CurrencyTrait;
    use DateTrait;

    const S3_BUCKET = 'policy.so-sure.com';
    const KEY_POLICY_QUEUE = 'policy:queue';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var SequenceService */
    protected $sequence;

    /** @var MailerService */
    protected $mailer;
    protected $smtp;
    protected $templating;

    /** @var RouterService */
    protected $routerService;
    protected $snappyPdf;
    protected $dispatcher;
    protected $s3;

    /** @var string */
    protected $environment;

    /** @var boolean */
    protected $skipS3;

    /** @var ShortLinkService */
    protected $shortLink;

    /** @var JudopayService */
    protected $judopay;

    protected $statsd;

    protected $redis;

    protected $branch;

    protected $address;

    protected $imeiValidator;

    protected $rateLimit;

    protected $intercom;

    /** @var SmsService */
    protected $sms;

    /** @var SCodeService */
    protected $scodeService;

    /** @var SixpackService */
    protected $sixpackService;

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
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param SequenceService  $sequence
     * @param MailerService    $mailer
     * @param                  $smtp
     * @param                  $templating
     * @param RouterService    $routerService
     * @param                  $environment
     * @param                  $snappyPdf
     * @param                  $dispatcher
     * @param                  $s3
     * @param ShortLinkService $shortLink
     * @param                  $statsd
     * @param                  $redis
     * @param                  $branch
     * @param                  $address
     * @param                  $imeiValidator
     * @param                  $rateLimit
     * @param                  $intercom
     * @param SmsService       $sms
     * @param SCodeService     $scodeService
     * @param SixpackService   $sixpackService
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        SequenceService $sequence,
        MailerService $mailer,
        $smtp,
        $templating,
        RouterService $routerService,
        $environment,
        $snappyPdf,
        $dispatcher,
        $s3,
        ShortLinkService $shortLink,
        $statsd,
        $redis,
        $branch,
        $address,
        $imeiValidator,
        $rateLimit,
        $intercom,
        SmsService $sms,
        SCodeService $scodeService,
        SixpackService $sixpackService
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
        $this->address = $address;
        $this->imeiValidator = $imeiValidator;
        $this->rateLimit = $rateLimit;
        $this->intercom = $intercom;
        $this->sms = $sms;
        $this->scodeService = $scodeService;
        $this->sixpackService = $sixpackService;
    }

    private function validateUser($user)
    {
        if (!$user->hasValidDetails() || !$user->hasValidBillingDetails()) {
            throw new InvalidUserDetailsException();
        }
        if (!$this->address->validatePostcode($user->getBillingAddress()->getPostCode())) {
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

        if (!$this->imeiValidator->checkSerial(
            $phone,
            $serialNumber,
            $imei,
            $user,
            $identityLog,
            $this->warnMakeModelMismatch
        )) {
            throw new ImeiPhoneMismatchException();
        }
        $checkmend['serialResponse'] = $this->imeiValidator->getResponseData();
        $checkmend['makeModelValidatedStatus'] = $this->imeiValidator->getMakeModelValidatedStatus();

        return $checkmend;
    }

    public function init(
        User $user,
        Phone $phone,
        $imei,
        $serialNumber,
        IdentityLog $identityLog = null,
        $phoneData = null
    ) {
        try {
            $this->validateUser($user);
            $this->validateImei($imei);

            if ($identityLog && $identityLog->isSessionDataPresent()) {
                if (!$this->rateLimit->allowedByDevice(
                    RateLimitService::DEVICE_TYPE_POLICY,
                    $identityLog->getIp(),
                    $identityLog->getCognitoId()
                )) {
                    throw new RateLimitException();
                }
            }

            $checkmend = $this->checkImeiSerial($user, $phone, $imei, $serialNumber, $identityLog);

            // TODO: items in POST /policy should be moved to service and service called here
            $policy = new SalvaPhonePolicy();
            $policy->setPhone($phone);
            $policy->setImei($imei);
            $policy->setSerialNumber($serialNumber);
            $policy->setIdentityLog($identityLog);
            $policy->setPhoneData($phoneData);

            $policyTermsRepo = $this->dm->getRepository(PolicyTerms::class);
            $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);
            $policy->init($user, $latestTerms);

            $policy->addCheckmendCertData($checkmend['imeiCertId'], $checkmend['imeiResponse']);
            $policy->addCheckmendSerialData($checkmend['serialResponse']);
            // saving final finaly checkmendcert based status
            $policy->setMakeModelValidatedStatus($checkmend['makeModelValidatedStatus']);
            return $policy;
        } catch (InvalidPremiumException $e) {
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'Invalid premium')
            );
            throw $e;
        } catch (InvalidUserDetailsException $e) {
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'Invalid User Details')
            );
            throw $e;
        } catch (GeoRestrictedException $e) {
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'Non-UK Address')
            );
            throw $e;
        } catch (DuplicateImeiException $e) {
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'IMEI Already In System')
            );
            throw $e;
        } catch (LostStolenImeiException $e) {
            $this->dispatchEvent(
                UserPaymentEvent::EVENT_FAILED,
                new UserPaymentEvent($user, 'IMEI Lost Or Stolen (so-sure)')
            );
            throw $e;
        } catch (ImeiBlacklistedException $e) {
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

    private function dispatchEvent($eventType, $event)
    {
        if ($this->dispatcher) {
            $this->dispatcher->dispatch($eventType, $event);
        } else {
            $this->logger->warning('Dispatcher is disabled for Policy Service');
        }
    }

    public function create(Policy $policy, \DateTime $date = null, $setActive = false, $numPayments = null)
    {
        $this->statsd->startTiming("policy.create");
        try {
            if (!$date) {
                $date = new \DateTime();
            }
            $user = $policy->getUser();

            $prefix = $policy->getPolicyPrefix($this->environment);
            if ($policy->isValidPolicy($prefix)) {
                $this->logger->warning(sprintf('Policy %s is valid, but attempted to re-create', $policy->getId()));

                return false;
            }

            if (count($policy->getScheduledPayments()) > 0) {
                throw new \Exception(sprintf('Policy %s is not valid, yet has scheduled payments', $policy->getId()));
            }

            // If policy hasn't yet been assigned a payer, default to the policy user
            if (!$policy->getPayer()) {
                $user->addPayerPolicy($policy);
            }

            if ($numPayments === null) {
                $this->generateScheduledPayments($policy, $date, $numPayments);
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

            if ($prefix) {
                $policy->create(
                    $this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE_INVALID),
                    $prefix,
                    $date
                );
            } else {
                $policy->create($this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE), null, $date);
            }

            $this->setPromoCode($policy);

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
            $repo = $this->dm->getRepository(PhonePolicy::class);
            $isPreLaunchPolicy = $repo->isPromoLaunch($policy->getPolicyNumberPrefix());
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

    private function queueMessage($policy)
    {
        $data = ['policyId' => $policy->getId()];
        $this->redis->rpush(self::KEY_POLICY_QUEUE, serialize($data));
    }

    public function clearQueue($max = null)
    {
        if (!$max) {
            $this->redis->del(self::KEY_POLICY_QUEUE);
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

                $this->generatePolicyFiles($policy);

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
            $this->newPolicyEmail($policy, [$policySchedule, $policyTerms], $bcc);
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

        $template = sprintf(
            'AppBundle:Pdf:policyTermsV%d.html.twig',
            $policy->getPolicyTerms()->getVersionNumber()
        );

        $this->snappyPdf->setOption('orientation', 'Landscape');
        $this->snappyPdf->setOption('lowquality', false);
        $this->snappyPdf->setOption('footer-center', $policy->getPolicyTerms()->getVersion());
        $this->snappyPdf->setOption('footer-font-size', 8);

        $this->snappyPdf->setOption('page-size', 'A4');
        $this->snappyPdf->setOption('margin-top', '0');
        $this->snappyPdf->setOption('margin-bottom', '5');
        $this->snappyPdf->generateFromHtml(
            $this->templating->render($template, ['policy' => $policy]),
            $tmpFile
        );

        $this->uploadS3($tmpFile, $filename, $policy);

        $policyTermsFile = new PolicyTermsFile();
        $policyTermsFile->setBucket(self::S3_BUCKET);
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
            'AppBundle:Pdf:policyScheduleV%d.html.twig',
            $policy->getPolicyTerms()->getVersionNumber()
        );

        $this->snappyPdf->setOption('orientation', 'Portrait');
        $this->snappyPdf->setOption('page-size', 'A4');
        $this->snappyPdf->setOption('margin-top', '0mm');
        $this->snappyPdf->generateFromHtml(
            $this->templating->render($template, ['policy' => $policy]),
            $tmpFile
        );

        $this->uploadS3($tmpFile, $filename, $policy);

        $policyScheduleFile = new PolicyScheduleFile();
        $policyScheduleFile->setBucket(self::S3_BUCKET);
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
            'Bucket' => self::S3_BUCKET,
            'Key'    => $s3Key,
            'SourceFile' => $file,
        ));
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
        while (!$policy->arePolicyScheduledPaymentsCorrect() &&
            ($scheduledPayment = $policy->getNextScheduledPayment()) !== null) {
            $scheduledPayments[] = $scheduledPayment;
            $scheduledPayment->cancel();
            $log[] = sprintf(
                'For Policy %s, cancelled scheduled payment %s on %s for £%0.2f',
                $policy->getPolicyNumber(),
                $scheduledPayment->getId(),
                $scheduledPayment->getScheduled()->format(\DateTime::ATOM),
                $scheduledPayment->getAmount()
            );
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

    public function regenerateScheduledPayments(
        Policy $policy,
        \DateTime $date = null,
        $numPayments = null,
        $billingOffset = null
    ) {
        $policy->cancelScheduledPayments();
        $this->generateScheduledPayments($policy, $date, $numPayments, $billingOffset);
    }

    public function generateScheduledPayments(
        Policy $policy,
        \DateTime $date = null,
        $numPayments = null,
        $billingOffset = null
    ) {
        // initial purchase is payment received then create policy
        // vs renewal which will have the number of payments requested
        $isInitialPurchase = $numPayments === null;
        if (!$date) {
            if (!$policy->getStart()) {
                throw new \Exception('Unable to generate payments if policy does not have a start date');
            }
            $date = clone $policy->getStart();
        } else {
            $date = clone $date;
        }

        // To determine any payments made
        $initialDate = clone $date;

        // To allow billing on same date every month, 28th is max allowable day on month
        if ($date->format('d') > 28) {
            $date->sub(new \DateInterval(sprintf('P%dD', $date->format('d') - 28)));
        }

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
        $paid = $policy->getTotalSuccessfulPayments($initialDate);
        if ($billingOffset) {
            $paid += $billingOffset;
        }
        $numPaidPayments = $policy->getPremium()->getNumberOfMonthlyPayments($paid);
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
        $numScheduledPayments = $numPayments - $numPaidPayments;
        for ($i = 1; $i <= $numScheduledPayments; $i++) {
            $scheduledDate = clone $date;
            // initial purchase should start at 1 month from initial purchase
            $scheduledDate->add(new \DateInterval(sprintf('P%dM', $isInitialPurchase ? $i : $i - 1)));

            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setScheduled($scheduledDate);
            if ($i == 1 && $numPayments == 1) {
                $scheduledPayment->setAmount($policy->getPremium()->getAdjustedYearlyPremiumPrice());
            } elseif ($i <= 11) {
                $scheduledPayment->setAmount($policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice());
            } else {
                $scheduledPayment->setAmount($policy->getPremium()->getAdjustedFinalMonthlyPremiumPrice());
            }
            $policy->addScheduledPayment($scheduledPayment);
        }
    }

    /**
     * @param Policy    $policy
     * @param string    $reason
     * @param boolean   $closeOpenClaims             Where we are required to cancel the policy (binder),
     *                                               we need to close out claims
     * @param \DateTime $date
     * @param boolean   $skipUnpaidMinTimeframeCheck Require at least 15 days from last unpaid status change
     */
    public function cancel(
        Policy $policy,
        $reason,
        $closeOpenClaims = false,
        \DateTime $date = null,
        $skipUnpaidMinTimeframeCheck = false
    ) {
        if ($reason == Policy::CANCELLED_UNPAID && !$skipUnpaidMinTimeframeCheck) {
            $logRepo = $this->dm->getRepository(LogEntry::class);
            $history = $logRepo->findOneBy([
                'objectId' => $policy->getId(),
                'data.status' => Policy::STATUS_UNPAID,
            ], ['loggedAt' => 'desc']);
            $now = $date;
            if (!$now) {
                $now = new \DateTime();
            }
            $loggedAt = new \DateTime();
            if ($history) {
                $loggedAt = $history->getLoggedAt();
            }
            $diff = $now->diff($loggedAt);
            if ($diff->days < 15) {
                throw new \Exception(sprintf(
                    'Unable to cancel unpaid policy %s/%s as less than 15 days in unpaid state.',
                    $policy->getPolicyNumber(),
                    $policy->getId()
                ));
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
        $policy->cancel($reason, $date);
        $this->dm->flush();

        $this->cancelledPolicyEmail($policy);
        $this->cancelledPolicySms($policy);

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

        $this->newPolicyEmail($policy, $attachments, 'bcc@so-sure.com');
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
     * @param Policy $policy
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
        $exp = $this->sixpackService->participate(
            SixpackService::EXPERIMENT_CANCELLATION,
            ['damage', 'cancel'],
            true,
            1,
            $policy->getUser()->getId()
        );

        try {
            $this->mailer->sendTemplate(
                sprintf('Your so-sure policy %s', $policy->getPolicyNumber()),
                $policy->getUser()->getEmail(),
                sprintf('%s.html.twig', $baseTemplate),
                ['policy' => $policy, 'cancellation_experiment' => $exp],
                sprintf('%s.txt.twig', $baseTemplate),
                ['policy' => $policy, 'cancellation_experiment' => $exp],
                $attachmentFiles,
                $bcc
            );
            $policy->setLastEmailed(new \DateTime());
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Failed sending policy email to %s', $policy->getUser()->getEmail()),
                ['exception' => $e]
            );
        }
    }

    /**
     * @param Policy $policy
     */
    public function weeklyEmail(Policy $policy)
    {
        if (!$this->mailer) {
            return;
        }

        // No need to send weekly email if pot is full
        if ($policy->isPotCompletelyFilled()) {
            return;
        }

        $repo = $this->dm->getRepository(EmailOptOut::class);
        if ($repo->isOptedOut($policy->getUser()->getEmail(), EmailOptOut::OPTOUT_CAT_WEEKLY)) {
            return;
        }

        try {
            $this->mailer->sendTemplate(
                sprintf('Happy Wednesday!'),
                $policy->getUser()->getEmail(),
                'AppBundle:Email:policy/weekly.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:policy/weekly.txt.twig',
                ['policy' => $policy],
                null,
                null,
                MailerService::EMAIL_WEEKLY
            );
            $policy->setLastEmailed(new \DateTime());

            return true;
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Failed sending policy weekly email to %s. Ex: %s',
                $policy->getUser()->getEmail(),
                $e->getMessage()
            ));

            return false;
        }
    }

    /**
     * @param Policy $policy
     */
    public function cancelledPolicyEmail(Policy $policy, $baseTemplate = null)
    {
        if (!$this->mailer) {
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

        $this->mailer->sendTemplate(
            sprintf('Your so-sure policy %s is now cancelled', $policy->getPolicyNumber()),
            $policy->getUser()->getEmail(),
            $htmlTemplate,
            ['policy' => $policy],
            $textTemplate,
            ['policy' => $policy],
            null,
            'bcc@so-sure.com'
        );
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

        $baseTemplate = sprintf('AppBundle:Email:davies/claimCancellation');
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $this->mailer->sendTemplate(
            sprintf('@%s Claim should be closed', $claim->getNumber()),
            'update-claim@wearesosure.com',
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

        $this->mailer->sendTemplate(
            $subject,
            $policy->getUser()->getEmail(),
            $htmlTemplate,
            ['policy' => $policy],
            $textTemplate,
            ['policy' => $policy],
            null
        );
    }

    public function getBreakdownData()
    {
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
        $now = new \DateTime();
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

    public function getPoliciesPendingCancellation($includeFuture = false, $prefix = null, \DateTime $date = null)
    {
        if (!$prefix) {
            $policy = new PhonePolicy();
            $prefix = $policy->getPolicyPrefix($this->environment);
            if (!$prefix) {
                $prefix = $policy->getPolicyNumberPrefix();
            }
        }
        $repo = $this->dm->getRepository(Policy::class);

        return $repo->findPoliciesForPendingCancellation($prefix, $includeFuture, $date);
    }

    public function getPoliciesForUnRenew(\DateTime $date = null)
    {
        $repo = $this->dm->getRepository(Policy::class);

        return $repo->findDeclinedRenewalPoliciesForUnRenewed($date);
    }

    public function getPoliciesForRenew(\DateTime $date = null)
    {
        $repo = $this->dm->getRepository(Policy::class);

        return $repo->findPendingRenewalPoliciesForRenewed($date);
    }

    public function cancelPoliciesPendingCancellation($prefix = null, $dryRun = false, \DateTime $date = null)
    {
        $cancelled = [];
        $policies = $this->getPoliciesPendingCancellation(false, $prefix, $date);
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

    public function unrenewPolicies($prefix = null, $dryRun = false, \DateTime $date = null)
    {
        // Have a feeling I will need prefix in the future here
        \AppBundle\Classes\NoOp::ignore([$prefix]);

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

    public function renewPolicies($prefix = null, $dryRun = false, \DateTime $date = null)
    {
        // Have a feeling I will need prefix in the future here
        \AppBundle\Classes\NoOp::ignore([$prefix]);

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

    public function cancelUnpaidPolicies($prefix, $dryRun = false, $skipUnpaidMinTimeframeCheck = false)
    {
        $cancelled = [];
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findBy(['status' => Policy::STATUS_UNPAID]);
        foreach ($policies as $policy) {
            if ($policy->shouldCancelPolicy($prefix)) {
                $cancelled[$policy->getId()] = $policy->getPolicyNumber();
                if (!$dryRun) {
                    try {
                        $this->cancel($policy, Policy::CANCELLED_UNPAID, true, null, $skipUnpaidMinTimeframeCheck);
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
        }

        return $cancelled;
    }

    public function activateRenewalPolicies($prefix, $dryRun = false, \DateTime $date = null)
    {
        $renewals = [];
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findRenewalPoliciesForActivation($prefix);
        foreach ($policies as $policy) {
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

    public function expireEndingPolicies($prefix, $dryRun = false, \DateTime $date = null)
    {
        $expired = [];
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findPoliciesForExpiration($prefix, $date);
        foreach ($policies as $policy) {
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

    public function fullyExpireExpiredClaimablePolicies($prefix, $dryRun = false, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $fullyExpired = [];
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findPoliciesForFullExpiration($prefix);
        foreach ($policies as $policy) {
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

    public function cashbackReminder($dryRun)
    {
        $now = new \DateTime();
        $cashback = [];
        $cashbackRepo = $this->dm->getRepository(Cashback::class);
        $cashbackItems = $cashbackRepo->findBy(['status' => Cashback::STATUS_MISSING]);
        foreach ($cashbackItems as $cashbackItem) {
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

    /**
     * @param Policy    $policy
     * @param \DateTime $date
     */
    public function expire(Policy $policy, \DateTime $date = null)
    {
        $policy->expire($date);
        // TODO: consider if we need to handle the pending renewal cancellation here at the same time
        // to avoid any timing issues
        $this->dm->flush();

        $this->expiredPolicyEmail($policy);

        $this->dispatchEvent(PolicyEvent::EVENT_EXPIRED, new PolicyEvent($policy));
    }

    /**
     * @param Policy    $policy
     * @param \DateTime $date
     */
    public function fullyExpire(Policy $policy, \DateTime $date = null)
    {
        $initialStatus = $policy->getStatus();
        $policy->fullyExpire($date);
        $this->dm->flush();

        // If no change in wait claim, then don't proceed as may resend emails for cashback
        if ($policy->getStatus() == Policy::STATUS_EXPIRED_WAIT_CLAIM &&
            $initialStatus == Policy::STATUS_EXPIRED_WAIT_CLAIM) {
            return null;
        }

        if ($policy->hasCashback() && !in_array($policy->getCashback()->getStatus(), [
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
            $outstanding = $policy->getNextPolicy()->getOutstandingPremiumToDate($date ? $date : new \DateTime(), true);
            $this->regenerateScheduledPayments($policy->getNextPolicy(), $date, null, $outstanding);

            // bill for outstanding payments due
            $outstanding = $policy->getNextPolicy()->getOutstandingUserPremiumToDate($date ? $date : new \DateTime());
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setScheduled($date ? $date : new \DateTime());
            $scheduledPayment->setAmount($outstanding);
            $policy->getNextPolicy()->addScheduledPayment($scheduledPayment);
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
        $this->mailer->sendTemplate(
            $subject,
            $policy->getUser()->getEmail(),
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
     * @param Policy $policy
     */
    public function activate(Policy $policy, \DateTime $date = null)
    {
        $policy->activate($date);
        $this->dm->flush();

        $this->dispatchEvent(PolicyEvent::EVENT_START, new PolicyEvent($policy));

        // Not necessary to email as already received docs at time of renewal
    }

    /**
     * @param Policy $policy
     */
    public function unrenew(Policy $policy, \DateTime $date = null)
    {
        $policy->unrenew($date);
        $this->dm->flush();

        // Might be an idea to email at this point,
        // although the policy end status is probably set at the same time
    }

    public function createPendingRenewalPolicies($prefix, $dryRun = false, \DateTime $date = null)
    {
        $pendingRenewal = [];
        $policyRepo = $this->dm->getRepository(Policy::class);
        $policies = $policyRepo->findPoliciesForPendingRenewal($prefix, $date);
        foreach ($policies as $policy) {
            if ($policy->canCreatePendingRenewal($date)) {
                $pendingRenewal[$policy->getId()] = $policy->getPolicyNumber();
                if (!$dryRun) {
                    $this->createPendingRenewal($policy, $date);
                }
            }
        }

        return $pendingRenewal;
    }

    public function notifyPendingCancellations($prefix, $days = null)
    {
        $count = 0;
        if (!$days) {
            $days = 5;
        }
        $policyRepo = $this->dm->getRepository(Policy::class);
        $date = new \DateTime();
        $date = $date->add(new \DateInterval(sprintf('P%dD', $days)));

        $pendingCancellationPolicies = $policyRepo->findPoliciesForPendingCancellation($prefix, false, $date);
        foreach ($pendingCancellationPolicies as $policy) {
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
            if ($policy->shouldCancelPolicy($prefix, $date) && $policy->hasOpenClaim()) {
                foreach ($policy->getClaims() as $claim) {
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

        $baseTemplate = sprintf('AppBundle:Email:davies/pendingCancellation');
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $subject = sprintf(
            '@%s Claim should be finalised',
            $claim->getNumber()
        );
        $this->mailer->sendTemplate(
            $subject,
            'update-claim@wearesosure.com',
            $htmlTemplate,
            ['claim' => $claim, 'cancellationDate' => $cancellationDate],
            null,
            null,
            null,
            'bcc@so-sure.com'
        );
    }

    public function createPendingRenewal(Policy $policy, \DateTime $date = null)
    {
        $policyTermsRepo = $this->dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);
        $newPolicy = $policy->createPendingRenewal($latestTerms, $date);

        $this->dm->persist($newPolicy);
        $this->dm->flush();

        $this->pendingRenewalEmail($policy);
        $this->dispatchEvent(PolicyEvent::EVENT_PENDING_RENEWAL, new PolicyEvent($policy));

        return $newPolicy;
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
        $this->mailer->sendTemplate(
            $subject,
            $policy->getUser()->getEmail(),
            $htmlTemplate,
            $data,
            $textTemplate,
            $data
        );
    }

    public function autoRenew(Policy $policy, \DateTime $date = null)
    {
        if ($policy->isFullyPaid()) {
            return $this->renew($policy, $policy->getPremiumInstallmentCount(), null, true, $date);
        } else {
            $this->logger->warning(sprintf(
                'Skipping renewal as policy %s/%s is not fully paid',
                $policy->getId(),
                $policy->getPolicyNumber()
            ));
            $policy->getNextPolicy()->setStatus(Policy::STATUS_UNRENEWED);
            $this->dm->flush();

            return false;
        }
    }

    public function renew(
        Policy $policy,
        $numPayments,
        Cashback $cashback = null,
        $autoRenew = false,
        \DateTime $date = null
    ) {
        if (!$date) {
            $date = new \DateTime();
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
            $discount = $policy->getPotValue();
        }

        if ($payer = $policy->getPayer()) {
            $payer->addPayerPolicy($newPolicy);
        }
        $this->create($newPolicy, $startDate, false, $numPayments);
        $newPolicy->renew($discount, $autoRenew, $date);
        $this->generateScheduledPayments($newPolicy, $startDate, $numPayments);

        $this->dm->flush();

        $this->dispatchEvent(PolicyEvent::EVENT_RENEWED, new PolicyEvent($policy));

        return $newPolicy;
    }

    public function declineRenew(Policy $policy, Cashback $cashback = null, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
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

    public function repurchase(Policy $policy, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        if (!$policy->canRepurchase()) {
            throw new \Exception(sprintf(
                'Unable to repurchase policy %s',
                $policy->getId()
            ));
        }

        $repo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $repo->findDuplicateImei($policy->getImei());
        foreach ($policies as $checkPolicy) {
            if (!$checkPolicy->getStatus() &&
                $checkPolicy->getUser()->getId() == $policy->getUser()->getId()) {
                return $checkPolicy;
            }
        }

        $policyTermsRepo = $this->dm->getRepository(PolicyTerms::class);
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

        $cashback->setDate(new \DateTime());
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
        $this->mailer->sendTemplate(
            $subject,
            $cashback->getPolicy()->getUser()->getEmail(),
            $htmlTemplate,
            $data,
            $textTemplate,
            $data
        );
    }
}
