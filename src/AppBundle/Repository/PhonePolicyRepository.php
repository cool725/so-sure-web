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
    public function countAllActivePoliciesWithPolicyDiscountToEndOfMonth(\DateTime $date = null)
    {
        $nextMonth = $this->endOfMonth($date);

        return $this->countAllActivePoliciesByInstallments(null, null, $nextMonth, true);
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
        \DateTime $endDate = null,
        $policyDiscountPresent = null
    ) {
        return $this->findAllActivePoliciesByInstallments(
            $installments,
            $startDate,
            $endDate,
            $policyDiscountPresent
        )->count();
    }

    /**
     * All policies that are active with number of installment payments (excluding so-sure test ones)
     */
    public function findAllActivePoliciesByInstallments(
        $installments,
        \DateTime $startDate = null,
        \DateTime $endDate = null,
        $policyDiscountPresent = null
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
        $qb->field('start')->lt($endDate);
        if ($startDate) {
            $qb->field('start')->gte($startDate);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'id');
        }
        if ($policyDiscountPresent !== null) {
            $qb->field('policyDiscountPresent')->equals($policyDiscountPresent);
        }

        return $qb->getQuery()
            ->execute();
    }

    public function findPoliciesForRewardPotLiability(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())))
            ->field('end')->gt($date);

        return $qb
            ->getQuery()
            ->execute();
    }

    /**
     * All policies that are new (excluding so-sure test ones)
     */
    public function countAllNewPolicies(\DateTime $endDate = null, \DateTime $startDate = null, $metric = null)
    {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));

        $qb->field('start')->lt($endDate);
        if ($startDate) {
            $qb->field('start')->gte($startDate);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'id');
        }
        if ($metric) {
            $qb->field('metrics')->equals($metric);
        }

        return $qb->getQuery()
            ->execute()
            ->count();
    }

    /**
     * All policies that are active (excluding so-sure test ones)
     *
     * @param \DateTime|null $startDate
     * @param \DateTime|null $endDate
     * @param string|null    $prefix
     * @param string|null    $excludeMetric
     * @return mixed
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function findAllActiveUnpaidPolicies(
        \DateTime $startDate = null,
        \DateTime $endDate = null,
        $prefix = null,
        $excludeMetric = null
    ) {
        if (!$endDate) {
            $endDate = new \DateTime();
        }
        if (!$prefix) {
            $policy = new PhonePolicy();
            $prefix = $policy->getPolicyNumberPrefix();
        }

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $prefix)));

        $qb->field('start')->lt($endDate);
        if ($startDate) {
            $qb->field('start')->gte($startDate);
        }
        if ($excludeMetric) {
            $qb->field('metrics')->notEqual($excludeMetric);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'id');
        }

        // print_r($qb->getQuery()->getQuery());

        return $qb->getQuery()
            ->execute();
    }

    /**
     * All policies that are active (excluding so-sure test ones)
     */
    public function findAllStartedPolicies($prefix = null, \DateTime $startDate = null, \DateTime $endDate = null)
    {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        if (!$prefix) {
            $policy = new PhonePolicy();
            $prefix = $policy->getPolicyNumberPrefix();
        }

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $prefix)));

        $qb->field('start')->lt($endDate);
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
        $qb->field('start')->lt($endDate);
        if ($startDate) {
            $qb->field('start')->gte($startDate);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'id');
        }

        return $qb->getQuery()
            ->execute();
    }

    public function findAllStatusUpdatedPolicies(\DateTime $startDate = null, \DateTime $endDate = null)
    {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));
        $qb->field('statusUpdated')->lt($endDate);
        if ($startDate) {
            $qb->field('statusUpdated')->gte($startDate);
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
    public function countAllEndingPolicies(
        $cancellationReason,
        \DateTime $startDate = null,
        \DateTime $endDate = null,
        $emptyCancellation = true,
        $metric = null
    ) {
        return $this->findAllEndingPolicies(
            $cancellationReason,
            false,
            $startDate,
            $endDate,
            false,
            $emptyCancellation,
            $metric
        )->count();
    }

    /**
     * All policies that are 'ending' (e.g. cancelled) during time period (excluding so-sure test ones)
     */
    public function findAllEndingPolicies(
        $cancellationReason,
        $onlyWithFNOL = false,
        \DateTime $startDate = null,
        \DateTime $endDate = null,
        $requestedCancellation = false,
        $emptyCancellation = true,
        $metric = null
    ) {
        if (!$endDate) {
            $endDate = new \DateTime();
        }

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));
/*
        $qb->field('status')->in([
            Policy::STATUS_CANCELLED,
            Policy::STATUS_EXPIRED_CLAIMABLE,
            Policy::STATUS_EXPIRED,
            Policy::STATUS_EXPIRED_WAIT_CLAIM,
        ]);*/
        if ($emptyCancellation || $cancellationReason) {
            $qb->field('cancelledReason')->equals($cancellationReason);
        }
        $qb->field('end')->lt($endDate);
        if ($startDate) {
            $qb->field('end')->gte($startDate);
        }

        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'id');
        }

        if ($metric) {
            $qb->field('metrics')->equals($metric);
        }

        if (!$onlyWithFNOL) {
            return $qb->getQuery()->execute();
        }

        $data = $qb->getQuery()
            ->execute()
            ->toArray();

        return array_filter($data, function ($policy) use ($requestedCancellation) {
            if ($requestedCancellation) {
                return count($policy->getClaims()) > 0 ||
                    $policy->hasRequestedCancellation();
            } else {
                return count($policy->getClaims()) > 0;
            }
        });
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
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
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

    public function getAllExpiredPoliciesForExport(\DateTime $date, $environment)
    {
        \AppBundle\Classes\NoOp::ignore([$date]);

        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_EXPIRED,
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
            ]);

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
                ->execute(['cursor' => true])
                ->toArray();
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

    public function findAllPolicies($environment)
    {
        $policy = new PhonePolicy();
        $qb = $this->createQueryBuilder();
        if ($environment == "prod") {
            $prodPolicyRegEx = new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix()));
            $qb->field('policyNumber')->equals($prodPolicyRegEx);
        } else {
            $qb->field('policyNumber')->notEqual(null);
        }

        return $qb->getQuery()
            ->execute();
    }

    /**
     * All pic-sure policies that have been created (excluding so-sure test ones) with given pic-sure status
     *
     * @param string|null $picSureStatus
     * @param array       $allTerms
     * @param boolean     $activeUnpaidOnly
     */
    public function countPicSurePolicies($picSureStatus, array $allTerms, $activeUnpaidOnly = false)
    {
        $policy = new PhonePolicy();
        $picsureTermsIds = [];
        foreach ($allTerms as $term) {
            if ($term->isPicSureEnabled()) {
                $picsureTermsIds[] = new \MongoId($term->getId());
            }
        }

        $qb = $this->createQueryBuilder()
            ->field('picSureStatus')->equals($picSureStatus)
            ->field('policyTerms.$id')->in($picsureTermsIds)
            ->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));

        if ($activeUnpaidOnly) {
            $qb = $qb->field('status')->equals([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID]);
        }

        return $qb
            ->getQuery()
            ->execute()
            ->count();
    }
}
