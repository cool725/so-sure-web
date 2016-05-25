<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyKeyFacts;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

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

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param SequenceService $sequence
     * @param \Swift_Mailer   $mailer
     * @param                 $templating
     * @param                 $router
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        SequenceService $sequence,
        \Swift_Mailer $mailer,
        $templating,
        $router
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->sequence = $sequence;
        $this->mailer = $mailer;
        $this->templating = $templating;
        $this->router = $router->getRouter();
    }

    public function create(Policy $policy, User $user)
    {
        $policyTermsRepo = $this->dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $policyKeyFactsRepo = $this->dm->getRepository(PolicyKeyFacts::class);
        $latestKeyFacts = $policyKeyFactsRepo->findOneBy(['latest' => true]);

        $policy->init($user, $latestTerms, $latestKeyFacts);

        // any emails with @so-sure.com will generate an invalid policy
        if ($user->hasSoSureEmail()) {
            $policy->create($this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE_INVALID), 'INVALID');
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

        $this->newPolicyEmail($policy);
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
    public function newPolicyEmail(Policy $policy)
    {
        $message = \Swift_Message::newInstance()
            ->setSubject(sprintf('Your so-sure policy %s', $policy->getPolicyNumber()))
            ->setFrom('hello@so-sure.com')
            ->setTo($policy->getUser()->getEmail())
            ->setBody(
                $this->templating->render('AppBundle:Email:policy/new.html.twig', ['policy' => $policy]),
                'text/html'
            )
            ->addPart(
                $this->templating->render('AppBundle:Email:policy/new.txt.twig', ['policy' => $policy]),
                'text/plain'
            );
        $this->mailer->send($message);
    }

    /**
     * @param Policy $policy
     */
    public function cancelledPolicyEmail(Policy $policy)
    {
        $message = \Swift_Message::newInstance()
            ->setSubject(sprintf('Your so-sure policy %s is now cancelled', $policy->getPolicyNumber()))
            ->setFrom('hello@so-sure.com')
            ->setTo($policy->getUser()->getEmail())
            ->setBody(
                $this->templating->render('AppBundle:Email:policy/cancelled.html.twig', ['policy' => $policy]),
                'text/html'
            )
            ->addPart(
                $this->templating->render('AppBundle:Email:policy/cancelled.txt.twig', ['policy' => $policy]),
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
                ->setFrom('hello@so-sure.com')
                ->setTo($networkConnection->getLinkedUser()->getEmail())
                ->setBody(
                    $this->templating->render('AppBundle:Email:policy/networkCancelled.html.twig', [
                        'policy' => $networkConnection->getLinkedPolicy(),
                        'cancelledUser' => $cancelledUser
                    ]),
                    'text/html'
                )
                ->addPart(
                    $this->templating->render('AppBundle:Email:policy/networkCancelled.txt.twig', [
                        'policy' => $networkConnection->getLinkedPolicy(),
                        'cancelledUser' => $cancelledUser
                    ]),
                    'text/plain'
                );
            $this->mailer->send($message);
        }
    }
}
