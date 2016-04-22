<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
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

    public function create(Policy $policy)
    {
        $policy->create($this->sequence->getSequenceId(SequenceService::SEQUENCE_PHONE));
        if ($policy instanceof PhonePolicy) {
            $repo = $this->dm->getRepository(PhonePolicy::class);
            if ($repo->countAllPolicies() < 1000) {
                $policy->setPromoCode(Policy::PROMO_LAUNCH);
            }
        }

        $this->dm->flush();
    }

    public function cancel(Policy $policy)
    {
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $this->dm->flush();
        // TODO - email user
        // TODO - cancel dd
        // TODO - adjust network pots
        // TODO - notify network?
        // TODO - do we want to lock user account?
    }
}
