<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyKeyFacts;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\User;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Service\SalvaExportService;
use AppBundle\Event\SalvaPolicyEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\InvalidPremiumException;

class PolicyService
{
    const S3_BUCKET = 'policy.so-sure.com';

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
    protected $mpdf;
    protected $dispatcher;
    protected $s3;

    /** @var string */
    protected $environment;

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param SequenceService $sequence
     * @param \Swift_Mailer   $mailer
     * @param                 $smtp
     * @param                 $templating
     * @param                 $router
     * @param                 $environment
     * @param                 $mpdf
     * @param                 $dispatcher
     * @param                 $s3
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
        $mpdf,
        $dispatcher,
        $s3
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->sequence = $sequence;
        $this->mailer = $mailer;
        $this->smtp = $smtp;
        $this->templating = $templating;
        $this->router = $router->getRouter();
        $this->environment = $environment;
        $this->mpdf = $mpdf;
        $this->dispatcher = $dispatcher;
        $this->s3 = $s3;
    }

    public function create(Policy $policy, \DateTime $date = null)
    {
        $user = $policy->getUser();
        $this->generateScheduledPayments($policy, $date);

        $prefix = null;
        if ($this->environment != 'prod') {
            $prefix = strtoupper($this->environment);
        } elseif ($user->hasSoSureEmail()) {
            // any emails with @so-sure.com will generate an invalid policy
            $prefix = 'INVALID';
        }

        if ($prefix) {
            $policy->create($this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE_INVALID), $prefix);
        } else {
            $policy->create($this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE));
        }
        if ($policy instanceof PhonePolicy) {
            $repo = $this->dm->getRepository(PhonePolicy::class);
            if ($repo->isPromoLaunch()) {
                $policy->setPromoCode(Policy::PROMO_LAUNCH);
            }
        }

        $this->dm->flush();

        $policyTerms = $this->generatePolicyTerms($policy);
        $policySchedule = $this->generatePolicySchedule($policy);

        $this->newPolicyEmail($policy, [$policySchedule, $policyTerms]);
        $this->dispatcher->dispatch(SalvaPolicyEvent::EVENT_CREATED, new SalvaPolicyEvent($policy));
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

        $this->mpdf->init('utf-8', 'A4-L', '', '', '15', '15', '5', '5');
        $this->mpdf->useTwigTemplate('AppBundle:Pdf:policyTerms.html.twig', ['policy' => $policy]);
        file_put_contents($tmpFile, $this->mpdf->generate());

        $this->uploadS3($tmpFile, $filename, $policy);

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

        $this->mpdf->init('utf-8', 'A4', '', '', '25', '25', '15', '10');
        $this->mpdf->useTwigTemplate('AppBundle:Pdf:policySchedule.html.twig', ['policy' => $policy]);
        file_put_contents($tmpFile, $this->mpdf->generate());

        $this->uploadS3($tmpFile, $filename, $policy);

        return $tmpFile;
    }

    public function uploadS3($file, $filename, Policy $policy)
    {
        if ($this->environment == "test") {
            return;
        }

        $s3Key = sprintf('%s/mob/%s/%s', $this->environment, $policy->getId(), $filename);

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
                return;
            } elseif ($paymentItem->getAmount() == $policy->getPremium()->getMonthlyPremiumPrice()) {
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
    }

    /**
     * @param Policy $policy
     */
    public function newPolicyEmail(Policy $policy, $attachmentFiles = null)
    {
        try {
            $message = \Swift_Message::newInstance()
                ->setSubject(sprintf('Your so-sure policy %s', $policy->getPolicyNumber()))
                ->setFrom('hello@wearesosure.com')
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
    public function cancelledPolicyEmail(Policy $policy)
    {
        $baseTemplate = sprintf('AppBundle:Email:policy-cancellation/%s', $policy->getCancelledReason());
        $htmlTemplate = sprintf("%s.html.twig", $baseTemplate);
        $textTemplate = sprintf("%s.txt.twig", $baseTemplate);

        $message = \Swift_Message::newInstance()
            ->setSubject(sprintf('Your so-sure policy %s is now cancelled', $policy->getPolicyNumber()))
            ->setFrom('hello@wearesosure.com')
            ->setTo($policy->getUser()->getEmail())
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
                ->setFrom('hello@wearesosure.com')
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
