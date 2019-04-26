<?php

namespace AppBundle\Repository\Invitation;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Repository\BaseDocumentRepository;

class InvitationRepository extends BaseDocumentRepository
{
    public function count($policies = null, \DateTime $start = null, \DateTime $end = null)
    {
        $qb = $this->createQueryBuilder();

        if ($policies) {
            $qb->field('policy.$id')->in($this->transformMongoIds($policies, 'getId'));
        }

        if ($start) {
            $qb->field('created')->gte($start);
        }
        if ($end) {
            $qb->field('created')->lte($end);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'policy.$id');
        }

        return $qb->getQuery()
            ->execute()
            ->count();
    }

    /**
     * Takes a policy and finds the policy that invited the owning user to join if one exists.
     * @param Policy $policy is the policy to look into.
     * @return Policy|null the inviter or null if there is no inviter.
     */
    public function getOwnInvitation($policy)
    {
        return $this->createQueryBuilder()
            ->field('invitee')->references($policy->getUser())
            ->getQuery()->getSingleResult();
    }
}
