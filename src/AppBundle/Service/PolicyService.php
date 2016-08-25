<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\User;
use AppBundle\Document\SCode;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Service\SalvaExportService;
use AppBundle\Event\PolicyEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\InvalidPremiumException;

class PolicyService
{
    const S3_BUCKET = 'policy.so-sure.com';
    const KEY_POLICY_QUEUE = 'policy:queue';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var SequenceService */
    protected $sequence;

    /** @var \Swift_Mailer */
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

    /** @var string */
    protected $defaultSenderAddress;

    /** @var string */
    protected $defaultSenderName;

    protected $statsd;

    protected $redis;

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
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
     * @param \Swift_Mailer    $mailer
     * @param                  $smtp
     * @param                  $templating
     * @param                  $router
     * @param                  $environment
     * @param                  $snappyPdf
     * @param                  $dispatcher
     * @param                  $s3
     * @param ShortLinkService $shortLink
     * @param string           $defaultSenderAddress
     * @param string           $defaultSenderName
     * @param                  $statsd
     * @param                  $redis
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        SequenceService $sequence,
        \Swift_Mailer $mailer,
        $smtp,
        $templating,
        $router,
        $environment,
        $snappyPdf,
        $dispatcher,
        $s3,
        ShortLinkService $shortLink,
        $defaultSenderAddress,
        $defaultSenderName,
        $statsd,
        $redis
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
        $this->defaultSenderAddress = $defaultSenderAddress;
        $this->defaultSenderName = $defaultSenderName;
        $this->statsd = $statsd;
        $this->redis = $redis;
    }

    public function create(Policy $policy, \DateTime $date = null)
    {
        $this->statsd->startTiming("policy.create");
        $user = $policy->getUser();

        $prefix = null;
        if ($this->environment != 'prod') {
            $prefix = strtoupper($this->environment);
        } elseif ($user->hasSoSureEmail()) {
            // any emails with @so-sure.com will generate an invalid policy
            $prefix = 'INVALID';
        }

        if ($policy->isValidPolicy($prefix)) {
            return;
        }

        if (count($policy->getScheduledPayments()) > 0) {
            throw new \Exception(sprintf('Policy %s is not valid, yet has scheduled payments', $policy->getId()));
        }

        $this->generateScheduledPayments($policy, $date);

        if ($prefix) {
            $policy->create($this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE_INVALID), $prefix, $date);
        } else {
            $policy->create($this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE), null, $date);
        }
        if ($policy instanceof PhonePolicy) {
            $repo = $this->dm->getRepository(PhonePolicy::class);
            if ($repo->isPromoLaunch()) {
                $policy->setPromoCode(Policy::PROMO_LAUNCH);
            }
        }

        $scode = $this->uniqueSCode($policy);
        $link = $this->router->generate('scode', ['code' => $scode->getCode()], true);
        $shortLink = $this->shortLink->addShortLink($link);
        $scode->setShareLink($shortLink);
        $this->dm->flush();

        $this->queueMessage($policy);

        $this->dispatcher->dispatch(PolicyEvent::EVENT_CREATED, new PolicyEvent($policy));

        $this->statsd->endTiming("policy.create");
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
            $policy->addSCode(new SCode());

            return $this->uniqueSCode($policy, $count + 1);
        }

        $exists = $repo->findOneBy(['code' => $scode->getCode()]);
        if ($exists) {
            // removing scode from policy seems to be problematic, so change code and make inactive
            $scode->deactivate();
            $policy->addSCode(new SCode());

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

    public function generateScheduledPayments(Policy $policy, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
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

            if ($paymentItem->getAmount() == $policy->getPremium()->getYearlyPremiumPrice()) {
                $policy->setPremiumInstallments(1);
                return;
            } elseif ($paymentItem->getAmount() == $policy->getPremium()->getMonthlyPremiumPrice()) {
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
                    'Invalid payment %f for policy %s',
                    $paymentItem->getAmount(),
                    $policy->getId()
                ));
            }
        }

        throw new \Exception(sprintf('Missing payment for policy %s', $policy->getId()));
    }

    public function cancel(Policy $policy, $reason, \DateTime $date = null)
    {
        $policy->cancel($reason, $date);
        $this->dm->flush();

        $this->cancelledPolicyEmail($policy);
        $this->networkCancelledPolicyEmails($policy);
        $this->dispatcher->dispatch(PolicyEvent::EVENT_CANCELLED, new PolicyEvent($policy));
    }

    /**
     * @param Policy $policy
     */
    public function newPolicyEmail(Policy $policy, $attachmentFiles = null)
    {
        try {
            $message = \Swift_Message::newInstance()
                ->setSubject(sprintf('Your so-sure policy %s', $policy->getPolicyNumber()))
                ->setFrom([$this->defaultSenderAddress => $this->defaultSenderName])
                ->setTo($policy->getUser()->getEmail())
                ->setBody(
                    $this->templating->render('AppBundle:Email:policy/new.html.twig', ['policy' => $policy]),
                    'text/html'
                )
                ->addPart(
                    $this->templating->render('AppBundle:Email:policy/new.txt.twig', ['policy' => $policy]),
                    'text/plain'
                );

            if ($attachmentFiles) {
                // If there's attachments, make sure we send directly to smtp, instead of queueing
                $mailer = new \Swift_Mailer($this->smtp);
                foreach ($attachmentFiles as $attachmentFile) {
                    $message->attach(\Swift_Attachment::fromPath($attachmentFile));
                }
            } else {
                $mailer = $this->mailer;
            }

            $mailer->send($message);
            $policy->setLastEmailed(new \DateTime());

            if ($attachmentFiles) {
                foreach ($attachmentFiles as $attachmentFile) {
                    unlink($attachmentFile);
                }
            }
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

        try {
            $message = \Swift_Message::newInstance()
                ->setSubject(sprintf('Your so-sure weekly email'))
                ->setFrom([$this->defaultSenderAddress => $this->defaultSenderName])
                ->setTo($policy->getUser()->getEmail())
                ->setBody(
                    $this->templating->render('AppBundle:Email:policy/weekly.html.twig', ['policy' => $policy]),
                    'text/html'
                )
                ->addPart(
                    $this->templating->render('AppBundle:Email:policy/weekly.txt.twig', ['policy' => $policy]),
                    'text/plain'
                );

            $this->mailer->send($message);
            $policy->setLastEmailed(new \DateTime());
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Failed sending policy weekly email to %s. Ex: %s',
                $policy->getUser()->getEmail(),
                $e->getMessage()
            ));
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

        $message = \Swift_Message::newInstance()
            ->setSubject(sprintf('Your so-sure policy %s is now cancelled', $policy->getPolicyNumber()))
            ->setFrom([$this->defaultSenderAddress => $this->defaultSenderName])
            ->setTo($policy->getUser()->getEmail())
            ->setBcc('bcc@so-sure.com')
            ->setBody(
                $this->templating->render($htmlTemplate, ['policy' => $policy]),
                'text/html'
            )
            ->addPart(
                $this->templating->render($textTemplate, ['policy' => $policy]),
                'text/plain'
            );
        $this->mailer->send($message);
    }

    /**
     * @param Policy $policy
     */
    public function networkCancelledPolicyEmails(Policy $policy)
    {
        $cancelledUser = $policy->getUser();
        foreach ($policy->getConnections() as $networkConnection) {
            // if that user has already claimed, there's no point in telling them that their friend cancelled,
            // as they can't do anything to improve their pot
            if ($networkConnection->getLinkedPolicy()->hasMonetaryClaimed()) {
                continue;
            }
            $message = \Swift_Message::newInstance()
                ->setSubject(sprintf('Your friend, %s, cancelled their so-sure policy', $cancelledUser->getName()))
                ->setFrom([$this->defaultSenderAddress => $this->defaultSenderName])
                ->setTo($networkConnection->getLinkedUser()->getEmail())
                ->setBody(
                    $this->templating->render('AppBundle:Email:policy-cancellation/network.html.twig', [
                        'policy' => $networkConnection->getLinkedPolicy(),
                        'cancelledUser' => $cancelledUser
                    ]),
                    'text/html'
                )
                ->addPart(
                    $this->templating->render('AppBundle:Email:policy-cancellation/network.txt.twig', [
                        'policy' => $networkConnection->getLinkedPolicy(),
                        'cancelledUser' => $cancelledUser
                    ]),
                    'text/plain'
                );
            $this->mailer->send($message);
        }
    }
}
