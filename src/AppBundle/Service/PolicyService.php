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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\InvalidPremiumException;

class PolicyService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var SequenceService */
    protected $sequence;

    /** @var \Swift_Mailer */
    protected $mailer;
    protected $templating;
    protected $router;
    protected $mpdf;

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
     * @param                 $templating
     * @param                 $router
     * @param                 $environment
     * @param                 $mpdf
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        SequenceService $sequence,
        \Swift_Mailer $mailer,
        $templating,
        $router,
        $environment,
        $mpdf
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->sequence = $sequence;
        $this->mailer = $mailer;
        $this->templating = $templating;
        $this->router = $router->getRouter();
        $this->environment = $environment;
        $this->mpdf = $mpdf;
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
            if ($repo->countAllPolicies() < 1000) {
                $policy->setPromoCode(Policy::PROMO_LAUNCH);
            }
        }

        $this->dm->flush();

        $policySchedule = $this->generatePolicySchedule($policy);

        $this->newPolicyEmail($policy, [$policySchedule]);
    }

    public function generatePolicySchedule(Policy $policy)
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'policy-schedule');
        unlink($tmpFile);
        $tmpFile = sprintf('%s.pdf', $tmpFile);
        print $tmpFile;

        $this->mpdf->init('utf-8', 'A4', '', '', '25', '25', '15', '10');
        $this->mpdf->useTwigTemplate('AppBundle:Pdf:policySchedule.html.twig', ['policy' => $policy]);
        file_put_contents($tmpFile, $this->mpdf->generate());

        // TODO: Upload schedule to s3 and update policy

        return $tmpFile;
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
        // TODO - cancel dd
    }

    /**
     * @param Policy $policy
     */
    public function newPolicyEmail(Policy $policy, $attachmentFiles = null)
    {
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
            
        // Make sure not to delete attachments as there's a timing issue
        // leave to a cleanup /tmp folder process
        if ($attachmentFiles) {
            foreach ($attachmentFiles as $attachmentFile) {
                $message->attach(\Swift_Attachment::fromPath($attachmentFile));
            }
        }
        $this->mailer->send($message);
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
