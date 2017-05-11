<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\User;
use AppBundle\Document\SCode;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\RewardConnection;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\File\PolicyTermsFile;
use AppBundle\Document\File\PolicyScheduleFile;

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
    protected $router;
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

    protected $warnMakeModelMismatch = true;

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
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
     * @param                  $router
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
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        SequenceService $sequence,
        MailerService $mailer,
        $smtp,
        $templating,
        $router,
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
        $rateLimit
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->sequence = $sequence;
        $this->mailer = $mailer;
        $this->smtp = $smtp;
        $this->templating = $templating;
        $this->router = $router->getRouter();
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
            $user,
            $identityLog,
            $this->warnMakeModelMismatch
        )) {
            throw new ImeiPhoneMismatchException();
        }
        $checkmend['serialResponse'] = $this->imeiValidator->getResponseData();

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

    public function create(Policy $policy, \DateTime $date = null, $setActive = false)
    {
        $this->statsd->startTiming("policy.create");
        try {
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

            $this->generateScheduledPayments($policy, $date);

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

            $scode = $this->uniqueSCode($policy);
            $shortLink = $this->branch->generateSCode($scode->getCode());
            // branch is preferred, but can fallback to old website version if branch is down
            if (!$shortLink) {
                $link = $this->router->generate(
                    'scode',
                    ['code' => $scode->getCode()],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );
                $shortLink = $this->shortLink->addShortLink($link);
            }
            $scode->setShareLink($shortLink);
            $this->dm->flush();

            $this->queueMessage($policy);

            if ($setActive) {
                $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
                $this->dm->flush();
            }

            // Dispatch should be last as there may be events that assume the policy is active
            // (e.g. intercom)
            $this->dispatchEvent(PolicyEvent::EVENT_CREATED, new PolicyEvent($policy));
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

    public function generatePolicyFiles($policy, $email = true)
    {
        $this->statsd->startTiming("policy.schedule+terms");
        $policyTerms = $this->generatePolicyTerms($policy);
        $policySchedule = $this->generatePolicySchedule($policy);
        $this->dm->flush();
        $this->statsd->endTiming("policy.schedule+terms");

        if ($email) {
            $this->newPolicyEmail($policy, [$policySchedule, $policyTerms]);
        }
    }

    public function uniqueSCode($policy, $count = 0)
    {
        if ($count > 10) {
            throw new \Exception('Too many unique scode attempts');
        }

        $repo = $this->dm->getRepository(SCode::class);
        $scode = $policy->getStandardSCode();
        if (!$scode) {
            $existingCount = $repo->getCountForName(SCode::getNameForCode($policy->getUser(), SCode::TYPE_STANDARD));
            $policy->createAddSCode($existingCount + 1 + $count);

            return $this->uniqueSCode($policy, $count + 1);
        }

        // scode created during the policy generation should not yet be persisted to the db
        // so if it does exist, its a duplicate code
        $exists = $repo->findOneBy(['code' => $scode->getCode()]);
        if ($exists) {
            // removing scode from policy seems to be problematic, so change code and make inactive
            $scode->deactivate();
            $existingCount = $repo->getCountForName(SCode::getNameForCode($policy->getUser(), SCode::TYPE_STANDARD));
            $policy->createAddSCode($existingCount + 1 + $count);

            return $this->uniqueSCode($policy, $count + 1);
        }

        return $scode;
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

        $this->snappyPdf->setOption('orientation', 'Landscape');
        $this->snappyPdf->setOption('lowquality', false);
        $this->snappyPdf->setOption('page-size', 'A4');
        $this->snappyPdf->setOption('margin-top', '0');
        $this->snappyPdf->setOption('margin-bottom', '0');
        $this->snappyPdf->generateFromHtml(
            $this->templating->render('AppBundle:Pdf:policyTerms.html.twig', ['policy' => $policy]),
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

        $this->snappyPdf->setOption('orientation', 'Portrait');
        $this->snappyPdf->setOption('page-size', 'A4');
        $this->snappyPdf->setOption('margin-top', '20mm');
        $this->snappyPdf->generateFromHtml(
            $this->templating->render('AppBundle:Pdf:policySchedule.html.twig', ['policy' => $policy]),
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
    public function adjustScheduledPayments(Policy $policy, \DateTime $date = null)
    {
        $log = [];
        $prefix = $policy->getPolicyPrefix($this->environment);
        if ($policy->arePolicyScheduledPaymentsCorrect($prefix, $date)) {
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
        if ($policy->arePolicyScheduledPaymentsCorrect($prefix, $date)) {
            $this->dm->flush();
            return null;
        }

        $scheduledPayments = [];
        // Try cancellating scheduled payments until amount matches
        while (!$policy->arePolicyScheduledPaymentsCorrect($prefix, $date) &&
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

        if ($policy->arePolicyScheduledPaymentsCorrect($prefix, $date)) {
            $this->dm->flush();
            $this->logger->warning(implode(PHP_EOL, $log));

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

    public function generateScheduledPayments(Policy $policy, \DateTime $date = null)
    {
        if (!$date) {
            // TODO: Should this be policy start date?
            $date = new \DateTime();
        } else {
            $date = clone $date;
        }

        // To allow billing on same date every month, 28th is max allowable day on month
        if ($date->format('d') > 28) {
            $date->sub(new \DateInterval(sprintf('P%dD', $date->format('d') - 28)));
        }

        $payment = null;
        foreach ($policy->getPayments() as $paymentItem) {
            if (!$paymentItem->isSuccess()) {
                continue;
            }

            if ($this->areEqualToFourDp($paymentItem->getAmount(), $policy->getPremium()->getYearlyPremiumPrice())) {
                $policy->setPremiumInstallments(1);
                return;
            } elseif ($this->areEqualToFourDp(
                $paymentItem->getAmount(),
                $policy->getPremium()->getMonthlyPremiumPrice()
            )) {
                $policy->setPremiumInstallments(12);
                for ($i = 1; $i <= 11; $i++) {
                    $scheduledDate = clone $date;
                    $scheduledDate->add(new \DateInterval(sprintf('P%dM', $i)));

                    $scheduledPayment = new ScheduledPayment();
                    $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
                    $scheduledPayment->setScheduled($scheduledDate);
                    $scheduledPayment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
                    $policy->addScheduledPayment($scheduledPayment);
                }
                return;
            } else {
                throw new InvalidPremiumException(sprintf(
                    'Invalid payment %f for policy %s [Expected %f or %f]',
                    $paymentItem->getAmount(),
                    $policy->getId(),
                    $policy->getPremium()->getYearlyPremiumPrice(),
                    $policy->getPremium()->getMonthlyPremiumPrice()
                ));
            }
        }

        throw new \Exception(sprintf('Missing payment for policy %s', $policy->getId()));
    }

    public function cancel(Policy $policy, $reason, $skipNetworkEmail = false, \DateTime $date = null)
    {
        $policy->cancel($reason, $date);
        $this->dm->flush();

        $this->cancelledPolicyEmail($policy);
        if (!$skipNetworkEmail) {
            $this->networkCancelledPolicyEmails($policy);
        }

        $this->dispatchEvent(PolicyEvent::EVENT_CANCELLED, new PolicyEvent($policy));
    }

    /**
     * @param Policy $policy
     */
    public function newPolicyEmail(Policy $policy, $attachmentFiles = null)
    {
        try {
            $this->mailer->sendTemplate(
                sprintf('Your so-sure policy %s', $policy->getPolicyNumber()),
                $policy->getUser()->getEmail(),
                'AppBundle:Email:policy/new.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:policy/new.txt.twig',
                ['policy' => $policy],
                $attachmentFiles
            );
            $policy->setLastEmailed(new \DateTime());
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Failed sending policy email to %s', $policy->getUser()->getEmail()));
        }
    }

    /**
     * @param Policy $policy
     */
    public function weeklyEmail(Policy $policy)
    {
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
    public function cancelledPolicyEmail(Policy $policy)
    {
        $baseTemplate = sprintf('AppBundle:Email:policy-cancellation/%s', $policy->getCancelledReason());
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
    public function networkCancelledPolicyEmails(Policy $policy)
    {
        $cancelledUser = $policy->getUser();
        foreach ($policy->getConnections() as $networkConnection) {
            if ($networkConnection instanceof RewardConnection) {
                continue;
            }
            // if that user has already claimed, there's no point in telling them that their friend cancelled,
            // as they can't do anything to improve their pot
            if ($networkConnection->getLinkedPolicy() &&
                $networkConnection->getLinkedPolicy()->hasMonetaryClaimed()) {
                continue;
            }
            $this->mailer->sendTemplate(
                sprintf('Your friend, %s, cancelled their so-sure policy', $cancelledUser->getName()),
                $networkConnection->getLinkedUser()->getEmail(),
                'AppBundle:Email:policy-cancellation/network.html.twig',
                ['policy' => $networkConnection->getLinkedPolicy(), 'cancelledUser' => $cancelledUser],
                'AppBundle:Email:policy-cancellation/network.txt.twig',
                ['policy' => $networkConnection->getLinkedPolicy(), 'cancelledUser' => $cancelledUser]
            );
        }
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

        return $repo->findPoliciesForCancellation($prefix, $includeFuture, $date);
    }

    public function cancelPoliciesPendingCancellation($prefix = null, \DateTime $date = null)
    {
        $cancelled = [];
        $policies = $this->getPoliciesPendingCancellation(false, $prefix, $date);
        foreach ($policies as $policy) {
            $this->cancel($policy, Policy::CANCELLED_USER_REQUESTED, false, $date);
            $cancelled[] = $policy;
        }

        return $cancelled;
    }
}
