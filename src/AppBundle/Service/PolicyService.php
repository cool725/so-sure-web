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

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param SequenceService $sequence
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        SequenceService $sequence
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->sequence = $sequence;
    }

    public function create(Policy $policy, User $user)
    {
        $policyTermsRepo = $this->dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $policyKeyFactsRepo = $this->dm->getRepository(PolicyKeyFacts::class);
        $latestKeyFacts = $policyKeyFactsRepo->findOneBy(['latest' => true]);

        $policy->init($user, $latestTerms, $latestKeyFacts);
        $policy->create($this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE));
        if ($policy instanceof PhonePolicy) {
            $repo = $this->dm->getRepository(PhonePolicy::class);
            if ($repo->countAllPolicies() < 1000) {
                $policy->setPromoCode(Policy::PROMO_LAUNCH);
            }
        }

        $this->dm->flush();
    }

    public function cancel(Policy $policy, $reason, \DateTime $date = null)
    {
        if ($date == null) {
            $date = new \DateTime();
        }
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setCancelledReason($reason);
        $policy->setEnd($date);

        // For now, just lock the user.  May want to allow the user to login in the future though...
        $user = $policy->getUser();
        $user->setLocked(true);

        // zero out the connection value for connections bound to this policy
        foreach ($policy->getConnections() as $networkConnection) {
            $networkConnection->clearValue();
            foreach ($networkConnection->getPolicy()->getConnections() as $otherConnection) {
                if ($otherConnection->getPolicy()->getId() == $policy->getId()) {
                    // TODO - notify network?
                    $otherConnection->clearValue();
                }
            }
        }
        $this->dm->flush();
        // TODO - email user
        // TODO - cancel dd
        // TODO - notify network?
        // TODO - cancellation reason
    }
}
