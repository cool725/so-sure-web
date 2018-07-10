<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\DateTrait;

class BacsPaymentRepository extends PaymentRepository
{
    use DateTrait;

    public function findPayments($month)
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

    public function findPaymentsIncludingNextMonth($month)
    {
        $startDay = $this->startOfMonth($month);
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
