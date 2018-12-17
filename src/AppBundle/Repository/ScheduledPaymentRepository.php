<?php

namespace AppBundle\Repository;

use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;

class ScheduledPaymentRepository extends BaseDocumentRepository
{
    use DateTrait;

    public function findScheduled(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        return $this->createQueryBuilder()
            ->field('payment')->equals(null)
            ->field('scheduled')->lte($date)
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
        if ($policy->getLastSuccessfulUserPaymentCredit()) {
            $date = clone $policy->getLastSuccessfulUserPaymentCredit()->getDate();
        } else {
            $date = clone $policy->getStart();
        }

        return $this->createQueryBuilder()
            ->field('policy')->references($policy)
            ->field('scheduled')->gte($date)
            ->field('status')->equals(ScheduledPayment::STATUS_FAILED)
            ->getQuery()
            ->execute()
            ->count();
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
}
