<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\DateTrait;

class PhonePolicyRepository extends DocumentRepository
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
     * All policies that have been created (excluding so-sure test ones)
     */
    public function countAllPolicies()
    {
        $policy = new PhonePolicy();

        return $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_PENDING,
                Policy::STATUS_ACTIVE,
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_UNPAID
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())))
            ->getQuery()
            ->execute()
            ->count();
    }

    /**
     * All policies that are active (excluding so-sure test ones)
     */
    public function countAllActivePolicies(\DateTime $date = null)
    {
        $nextMonth = $this->endOfMonth($date);

        $policy = new PhonePolicy();

        return $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())))
            ->field('start')->lt($nextMonth)
            ->getQuery()
            ->execute()
            ->count();
    }

    public function isPromoLaunch()
    {
        return $this->countAllPolicies() < 1000;
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

    public function getWeeklyEmail($environment)
    {
        $lastWeek = new \DateTime();
        $lastWeek->sub(new \DateInterval('P1W'));
        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder();
        $qb->addAnd($qb->expr()->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID
        ]));
        $qb->addAnd(
            $qb->expr()->addOr($qb->expr()->field('lastEmailed')->lte($lastWeek))
                ->addOr($qb->expr()->field('lastEmailed')->exists(false))
        );

        if ($environment == "prod") {
            $qb->addAnd($qb->expr()->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix()))));
        } else {
            $qb->addAnd($qb->expr()->field('policyNumber')->notEqual(null));
        }

        return $qb->getQuery()
            ->execute();
    }
}
