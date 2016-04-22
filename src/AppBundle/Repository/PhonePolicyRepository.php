<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;

class PhonePolicyRepository extends DocumentRepository
{
    public function isMissingOrExpiredOnlyPolicy($imei)
    {
        // if there's an imei in the db, only allow insurance for an expired policy
        // TODO: may want to allow a new policy if within 1 month of expiration and same user
        // TODO: consider if we want to allow an unpaid or cancelled policy on a different user?
        return $this->createQueryBuilder()
            ->field('imei')->equals($imei)
            ->field('status')->notEqual('expired')
            ->getQuery()
            ->execute()
            ->count() == 0;
    }

    /**
     * All policies that have been created
     */
    public function countAllPolicies()
    {
        return $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_PENDING,
                Policy::STATUS_ACTIVE,
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_UNPAID
            ])
            ->getQuery()
            ->execute()
            ->count();
    }
}
