<?php

namespace AppBundle\Repository;

use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;

class ScheduledPaymentRepository extends DocumentRepository
{
    use DateTrait;

    public function findScheduled(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
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
            $date = new \DateTime();
        }
        $start = $this->startOfMonth($date);
        $end = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->field('scheduled')->gte($start)
            ->field('scheduled')->lt($end)
            ->sort('scheduled', 'asc')
            ->getQuery()
            ->execute();
    }

    public function countUnpaidScheduledPayments(Policy $policy, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        } else {
            $date = clone $date;
        }
        $date->sub(new \DateInterval('P1M'));

        return $this->createQueryBuilder()
            ->field('policy')->references($policy)
            ->field('scheduled')->gte($date)
            ->field('status')->equals(ScheduledPayment::STATUS_FAILED)
            ->getQuery()
            ->execute()
            ->count();
    }
}
