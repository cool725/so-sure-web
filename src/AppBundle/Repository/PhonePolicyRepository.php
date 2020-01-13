<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\MongoDB\Aggregation\Builder;
use Doctrine\ODM\MongoDB\Cursor;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\DateTrait;

class PhonePolicyRepository extends PolicyRepository
{
    use DateTrait;

    const VALID_REGEX = '/^((?!INVALID).)*$/';

    /**
     * Finds all policies that have the given imei.
     * @return Cursor over the found imeis.
     */
    public function findDuplicateImei($imei)
    {
        return $this->createQueryBuilder()->field('imei')->equals((string) $imei)->getQuery()->execute();
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
            $endDate = \DateTime::createFromFormat('U', time());
        }
        $qb = $this->createQueryBuilder()
            ->field('status')->in([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID, Policy::STATUS_PICSURE_REQUIRED])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));
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
        return $qb->getQuery()->execute();
    }

    public function findPoliciesForRewardPotLiability(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $qb = $this->createQueryBuilder();
        $qb->addAnd(
            $qb->expr()->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_PICSURE_REQUIRED
            ])
        );
        $qb->addAnd($qb->expr()->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX)));
        $qb->addAnd($qb->expr()->field('potValue')->gt(0));
        $qb->addAnd($qb->expr()->field('end')->gt($date));
        $qb->addAnd(
            $qb->expr()->addOr($qb->expr()->field('potValue')->gt(0))
                ->addOr($qb->expr()->field('promoPotValue')->gt(0))
        );
        return $qb->getQuery()->execute();
    }

    /**
     * All policies that are new (excluding so-sure test ones)
     */
    public function countAllNewPolicies(\DateTime $endDate = null, \DateTime $startDate = null, $metric = null)
    {
        if (!$endDate) {
            $endDate = \DateTime::createFromFormat('U', time());
        }
        $qb = $this->createQueryBuilder()->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));
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
     * @param string|null    $excludeMetric
     * @return mixed
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function findAllActiveUnpaidPolicies(
        \DateTime $startDate = null,
        \DateTime $endDate = null,
        $excludeMetric = null
    ) {
        if (!$endDate) {
            $endDate = \DateTime::createFromFormat('U', time());
        }

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_PICSURE_REQUIRED
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));

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
    public function findAllStartedPolicies(\DateTime $startDate = null, \DateTime $endDate = null)
    {
        if (!$endDate) {
            $endDate = \DateTime::createFromFormat('U', time());
        }

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_PICSURE_REQUIRED,
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));

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

    public function countAllStartedPolicies(\DateTime $startDate = null, \DateTime $endDate = null)
    {
        return count($this->findAllStartedPolicies($startDate, $endDate));
    }

    /**
     * All policies that are 'new' (e.g. created) during time period (excluding so-sure test ones)
     */
    public function findAllNewPolicies($leadSource = null, \DateTime $startDate = null, \DateTime $endDate = null)
    {
        if (!$endDate) {
            $endDate = \DateTime::createFromFormat('U', time());
        }

        $qb = $this->createQueryBuilder()
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));

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
            $endDate = \DateTime::createFromFormat('U', time());
        }

        $qb = $this->createQueryBuilder()
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));
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
            $endDate = \DateTime::createFromFormat('U', time());
        }

        $qb = $this->createQueryBuilder()
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));
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



    public function getPotValues()
    {
        return $this->getDocumentManager()->getDocumentCollection($this->getClassName())->createAggregationBuilder()
                ->match()->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX))
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
            ->field('status')->in([Policy::STATUS_ACTIVE, Policy::STATUS_UNPAID, Policy::STATUS_PICSURE_REQUIRED])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));
        return $qb->getQuery()->execute();
    }

    public function getUnpaidPolicies()
    {
        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_UNPAID
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));
        return $qb->getQuery()->execute();
    }

    /**
     * Gives you a cursor to every single policy with a proper policy number prefix.
     * @return Cursor to all the policies.
     */
    public function findAllPolicies()
    {
        return $this->createQueryBuilder()
            ->field('policyNumber')->equals(self::VALID_REGEX)
            ->getQuery()->execute();
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
        $picsureTermsIds = [];
        foreach ($allTerms as $term) {
            if ($term->isPicSureEnabled()) {
                $picsureTermsIds[] = new \MongoId($term->getId());
            }
        }
        $qb = $this->createQueryBuilder()
            ->field('picSureStatus')->equals($picSureStatus)
            ->field('policyTerms.$id')->in($picsureTermsIds)
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));
        if ($activeUnpaidOnly) {
            $qb = $qb->field('status')->equals([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_PICSURE_REQUIRED
            ]);
        }
        return $qb
            ->getQuery()
            ->execute()
            ->count();
    }

    /**
     * Finds all policies that ended between the given dates and with the given status.
     * @param array|String|null $status is the status of the policies that we are looking for or null to not check.
     * @param \DateTime         $start  is the date that policies must have ended after if it's given.
     * @param \DateTime         $end    is the date that policies must have ended before if it's given.
     * @return array containing all the found policies.
     */
    public function findEndingByStatus($status = null, \DateTime $start = null, \DateTime $end = null)
    {
        $qb = $this->createQueryBuilder()->field("policyNumber")->equals(new \MongoRegex(self::VALID_REGEX));
        if (is_array($status)) {
            $qb->field("status")->in($status);
        } elseif ($status) {
            $qb->field("status")->equals($status);
        }
        if ($start) {
            $qb->field("end")->gte($start);
        }
        if ($end) {
            $qb->field("end")->lt($end);
        }
        return $qb->getQuery()->execute();
    }

    /**
     * Counts all policies that ended between the given dates and with the given status.
     * @param String    $status is the status of the policies that we are looking for.
     * @param \DateTime $start  is the date that the policies must have ended after if it's given.
     * @param \DateTime $end    is the date that policies must have ended before if it's given.
     * @return int the number of policies that were found.
     */
    public function countEndingByStatus($status, \DateTime $start = null, \DateTime $end = null)
    {
        return count($this->findEndingByStatus($status, $start, $end));
    }
}
