<?php

namespace AppBundle\Repository;

use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;

class ChargeRepository extends DocumentRepository
{
    use DateTrait;

    public function findMonthly(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $start = $this->startOfMonth($date);
        $end = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->field('createdDate')->gte($start)
            ->field('createdDate')->lt($end)
            ->sort('scheduled', 'asc')
            ->getQuery()
            ->execute();
    }
}
