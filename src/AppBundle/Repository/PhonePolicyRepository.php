<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\DateTrait;

class PhonePolicyRepository extends PolicyRepository
{
    use DateTrait;

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
     * All policies that are active (excluding so-sure test ones)
     */
    public function countAllActivePoliciesToEndOfMonth(\DateTime $date = null)
    {
        $nextMonth = $this->endOfMonth($date);

        return $this->countAllActivePolicies($nextMonth);
    }

    /**
     * All policies that are active (excluding so-sure test ones)
     */
    public function countAllActivePolicies(\DateTime $endDate = null, \DateTime $startDate = null)
    {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));

        $qb->field('start')->lte($endDate);
        if ($startDate) {
            $qb->field('start')->gte($startDate);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'id');
        }

        return $qb->getQuery()
            ->execute()
            ->count();
    }

    /**
     * All policies that are active (excluding so-sure test ones)
     */
    public function findAllActivePolicies($leadSource, \DateTime $startDate = null, \DateTime $endDate = null)
    {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));

        $qb->field('leadSource')->equals($leadSource);
        $qb->field('start')->lte($endDate);
        if ($startDate) {
            $qb->field('start')->gte($startDate);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'id');
        }

        return $qb->getQuery()
            ->execute();
    }

    public function getAllPoliciesForExport(\DateTime $date, $environment)
    {
        \AppBundle\Classes\NoOp::noOp([$date]);

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_PENDING,
                Policy::STATUS_ACTIVE,
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_UNPAID
            ]);

        if ($environment == "prod") {
            $qb->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));
        } else {
            $qb->field('policyNumber')->notEqual(null);
        }

        return $qb->getQuery()
            ->execute();
    }
}
