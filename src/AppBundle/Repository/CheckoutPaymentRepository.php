<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\DateTrait;

class CheckoutPaymentRepository extends PaymentRepository
{
    use DateTrait;

    public function findTransaction($date, $amount, $cardLastFour)
    {
        $startDay = $this->startOfDay($date);
        $nextDay = $this->endOfDay($date);

        return $this->createQueryBuilder()
            ->field('success')->equals(true)
            ->field('amount')->equals((double) $amount)
            ->field('date')->gte($startDay)
            ->field('date')->lt($nextDay)
            ->field('cardLastFour')->equals($cardLastFour)
            ->getQuery()
            ->execute();
    }
}
