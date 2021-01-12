<?php

namespace AppBundle\Repository;

use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;

class ScheduledPaymentRepository extends BaseDocumentRepository
{
    use DateTrait;

    /**
     * Finds all rescheduled payments for a given user and optionally also within certain dates.
     * @param Policy    $policy is the policy to find rescheduled payments for.
     * @param \DateTime $start  is the minimum date that the payments must be scheduled for or null for no minimum.
     * @return array containing all the found rescheduled payments.
     */
    public function findRescheduled($policy, \DateTime $start = null, \DateTime $end = null)
    {
        $query = $this->createQueryBuilder()
            ->field("status")->equals(ScheduledPayment::STATUS_SCHEDULED)
            ->field("type")->equals(ScheduledPayment::TYPE_RESCHEDULED)
            ->field("policy")->references($policy)
            ->field("payment")->equals(null);
        if ($start) {
            $query->field("scheduled")->gte($start);
        }
        if ($end) {
            $query->field("scheduled")->lt($end);
        }
        return $query->getQuery()->execute();
    }

    /**
     * Gives you the amount of money a given policy currently has rescheduled to pay.
     * @param Policy $policy is the policy that must pay this money.
     * @return float the amount of money.
     */
    public function getRescheduledAmount(Policy $policy)
    {
        $total = 0;
        foreach ($this->findRescheduled($policy) as $payment) {
            $total += $payment->getAmount();
        }
        return $total;
    }

    /**
     * Finds all scheduled payments labelled as being for bacs.
     * @param \DateTime|null $date       is the maximum date they can be scheduled for to be found.
     * @param string|null    $policyType is an optional type of policy to limit for.
     * @param int            $limit      is the maximum to return. If less than zero not used.
     * @return Cursor over the found scheduled payments.
     */
    public function findScheduledBacs(\DateTime $date = null, $policyType = null, $limit = -1)
    {
        $date = $date ?: new \DateTime();
        $query = $this->createQueryBuilder()
            ->field('payment')->equals(null)
            ->field('scheduled')->lt($date)
            ->field('status')->equals(ScheduledPayment::STATUS_SCHEDULED)
            ->field('paymentType')->equals('bacs');
        if ($policyType) {
            $query->field('policy.policy_type')->equals($policyType);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }
        return $query->getQuery()->execute();
    }

    public function findScheduled(\DateTime $date = null, $policyType = null, $limit = -1)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $query = $this->createQueryBuilder()
            ->field('payment')->equals(null)
            ->field('scheduled')->lt($date)
            ->field('status')->equals(ScheduledPayment::STATUS_SCHEDULED);
        if ($policyType) {
            $query->field('policy.policy_type')->equals($policyType);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }
        return $query->getQuery()->execute();
    }

    /**
     * Gets all scheduled payments that still have status scheduled and do not have a payment associated yet.
     * @return Cursor pointing to the returned set of scheduled payments.
     */
    public function findAllScheduled()
    {
        return $this->createQueryBuilder()
                ->field('payment')->equals(null)
                ->field('status')->equals(ScheduledPayment::STATUS_SCHEDULED)
                ->getQuery()
                ->execute();
    }

    public function findMonthlyScheduled(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $start = $this->startOfMonth($date);
        $end = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->eagerCursor(true)
            ->field('policy')->prime(true)
            ->field('scheduled')->gte($start)
            ->field('scheduled')->lt($end)
            ->sort('scheduled', 'asc')
            ;
    }

    public function countUnpaidScheduledPayments(Policy $policy)
    {
        $latestSuccessful = $policy->getLatestSuccessfulScheduledPayment();
        $date = $latestSuccessful ? $latestSuccessful->getScheduled() : $policy->getStart();
        return $this->createQueryBuilder()
            ->field('policy')->references($policy)
            ->field('scheduled')->gte($date)
            ->field('status')->in([ScheduledPayment::STATUS_FAILED, ScheduledPayment::STATUS_REVERTED])
            ->getQuery()
            ->execute()
            ->count();
    }

    /**
     * Finds the most recent scheduled payment that has a given status.
     * @param Policy $policy   is the policy that the payment belongs to.
     * @param array  $statuses are the statuses to look for in this payment.
     * @return ScheduledPayment the most recent of the types you wanted, or null if there is nothing there.
     */
    public function mostRecentWithStatuses(
        $policy,
        array $statuses = [ScheduledPayment::STATUS_SCHEDULED]
    ) {
        /** @var ScheduledPayment $scheduledPayment */
        $scheduledPayment = $this->createQueryBuilder()
            ->field("policy")->references($policy)
            ->field("status")->in($statuses)
            ->sort("scheduled", "desc")
            ->getQuery()
            ->getSingleResult();
        return $scheduledPayment;
    }

    public function getMonthlyValues()
    {
        $collection = $this->getDocumentManager()->getDocumentCollection($this->getClassName());
        $builder = $collection->createAggregationBuilder();
        return  $builder->match()
                ->field('status')
                ->in([ScheduledPayment::STATUS_SCHEDULED, ScheduledPayment::STATUS_SUCCESS])
                ->field('policy.$id')
                ->notIn($this->excludedPolicyIds ? $this->excludedPolicyIds : [])
                ->group()
                    ->field('_id')
                    ->expression(
                        $builder->expr()
                            ->field('year')
                            ->year('$scheduled')
                            ->field('month')
                            ->month('$scheduled')
                    )
                    ->field('total')
                    ->sum('$amount')
                ->sort('_id', 'desc')
            ->execute(['cursor' => true])
            ->toArray();
    }

    public function getPastScheduledWithNoStatusUpdate(Policy $policy, $date)
    {
        return $this->createQueryBuilder()
            ->field('policy')->references($policy)
            ->field("status")->equals(ScheduledPayment::STATUS_SCHEDULED)
            ->field("scheduled")->lt($date)
            ->sort("scheduled", "desc")
            ->getQuery()
            ->execute();

    }

    /**
     * Takes a scheduled payment and returns the scheduled payment that reschedules it.
     * @param ScheduledPayment $scheduledPayment is the scheduled payment that is rescheduled.
     * @return ScheduledPayment|null the scheduled payment if it exists or null if there is nothing.
     */
    public function getRescheduledBy($scheduledPayment)
    {
        return $this->createQueryBuilder()
            ->field("previousAttempt")->references($scheduledPayment)
            ->getQuery()
            ->execute()
            ->getSingleResult();
    }

    /**
     * Gives a list of all scheduled payments for the given policy that are set as scheduled.
     * @param Policy $policy is the policy for which to find the scheduled payments.
     * @return array of all the scheduled payments that were found.
     */
    public function getStillScheduled(Policy $policy)
    {
        return $this->createQueryBuilder()
            ->field("policy")->references($policy)
            ->field("status")->equals(ScheduledPayment::STATUS_SCHEDULED)
            ->sort("scheduled", "desc")
            ->getQuery()
            ->execute();
    }
}
