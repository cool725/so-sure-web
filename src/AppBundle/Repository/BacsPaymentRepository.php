<?php

namespace AppBundle\Repository;

use AppBundle\Document\Payment\BacsPayment;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\DateTrait;

class BacsPaymentRepository extends PaymentRepository
{
    use DateTrait;

    public function findPayments(\DateTime $month)
    {
        $startDay = $this->startOfMonth($month);
        $nextDay = $this->endOfMonth($month);

        return $this->createQueryBuilder()
            ->field('date')->gte($startDay)
            ->field('date')->lt($nextDay)
            ->sort('date', 'desc')
            ->sort('serialNumber', 'desc')
            ->getQuery()
            ->execute();
    }

    public function findSubmittedPayments(\DateTime $date)
    {
        return $this->createQueryBuilder()
            ->field('status')->equals(BacsPayment::STATUS_SUBMITTED)
            ->field('bacsReversedDate')->lte($date)
            ->getQuery()
            ->execute();
    }

    public function findPaymentsIncludingPreviousNextMonth(\DateTime $month)
    {
        $previousMonth = clone $month;
        $previousMonth = $previousMonth->sub(new \DateInterval('P1M'));
        $startDay = $this->startOfMonth($previousMonth);
        $nextDay = $this->endOfMonth($this->endOfMonth($month));

        return $this->createQueryBuilder()
            ->field('date')->gte($startDay)
            ->field('date')->lt($nextDay)
            ->sort('date', 'desc')
            ->sort('serialNumber', 'desc')
            ->getQuery()
            ->execute();
    }
}
