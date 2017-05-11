<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\MongoDB\Aggregation\Builder;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\DateTrait;

class PhonePolicyRepository extends PolicyRepository
{
    use DateTrait;

    public function findDuplicateImei($imei)
    {
        return $this->createQueryBuilder()
            ->field('imei')->equals((string) $imei)
            ->getQuery()
            ->execute();
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
        return $this->countAllActivePoliciesByInstallments(null, $startDate, $endDate);
    }

    /**
     * All policies that are active with number of installment payments (excluding so-sure test ones)
     */
    public function countAllActivePoliciesByInstallments(
        $installments,
        \DateTime $startDate = null,
        \DateTime $endDate = null
    ) {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));

        if ($installments) {
            $qb->field('premiumInstallments')->equals($installments);
        }
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
     * All policies that are new (excluding so-sure test ones)
     */
    public function countAllNewPolicies(\DateTime $endDate = null, \DateTime $startDate = null)
    {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
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
    public function findAllActivePolicies(\DateTime $startDate = null, \DateTime $endDate = null)
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
            ->execute();
    }

    /**
     * All policies that are 'new' (e.g. created) during time period (excluding so-sure test ones)
     */
    public function findAllNewPolicies($leadSource = null, \DateTime $startDate = null, \DateTime $endDate = null)
    {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
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

    /**
     * All policies that are 'ending' (e.g. cancelled) during time period (excluding so-sure test ones)
     */
    public function findAllEndingPolicies($cancellationReason, \DateTime $startDate = null, \DateTime $endDate = null)
    {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));

        if ($cancellationReason) {
            $qb->field('cancelledReason')->equals($cancellationReason);
        }
        $qb->field('end')->lte($endDate);
        if ($startDate) {
            $qb->field('end')->gte($startDate);
        }

        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'id');
        }

        return $qb->getQuery()
            ->execute()
            ->count();
    }

    public function getAllPoliciesForExport(\DateTime $date, $environment)
    {
        \AppBundle\Classes\NoOp::ignore([$date]);

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_UNPAID
            ])
            ->field('premiumInstallments')->gt(0);

        if ($environment == "prod") {
            $qb->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));
        } else {
            $qb->field('policyNumber')->notEqual(null);
        }

        return $qb->getQuery()
            ->execute();
    }

    public function getPotValues()
    {
        $policy = new PhonePolicy();

        return $this->getDocumentManager()->getDocumentCollection($this->getClassName())->createAggregationBuilder()
                ->match()
                    ->field('policyNumber')
                    ->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())))
                ->group()
                    ->field('_id')->expression(0)
                    ->field('potValue')
                    ->sum('$potValue')
                    ->field('promoPotValue')
                    ->sum('$promoPotValue')
                ->execute();
    }

    public function getActiveInvalidPolicies()
    {
        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', Policy::PREFIX_INVALID)));

        return $qb->getQuery()->execute();
    }

    public function getUnpaidPolicies()
    {
        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_UNPAID
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));

        return $qb->getQuery()->execute();
    }
}
