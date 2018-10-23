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

    public function findMonthly(\DateTime $date = null, $type = null, $excludeInvoiced = false,
        $affiliate = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $start = $this->startOfMonth($date);
        $end = $this->endOfMonth($date);

        $qb = $this->createQueryBuilder()
            ->field('createdDate')->gte($start)
            ->field('createdDate')->lt($end);

        if (is_array($type)) {
            $qb->field('type')->in($type);
        } elseif ($type) {
            $qb->field('type')->equals($type);
        }

        if ($excludeInvoiced) {
            $qb->field('invoice')->exists(false);
        }

        if ($type == 'affiliate' && $affiliate) {
            $qb->field('affiliate')->references($affiliate);
        }

        return $qb->sort('createdDate', 'asc')
            ->getQuery()
            ->execute();
    }
}
