<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Cashback;
use AppBundle\Document\DateTrait;

class CashbackRepository extends DocumentRepository
{
    use DateTrait;

    public function getPaidCashback(\DateTime $date)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->field('status')->equals(Cashback::STATUS_PAID)
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->getQuery()
            ->execute();
    }
}
