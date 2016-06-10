<?php

namespace AppBundle\Repository;

use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;

class ScheduledPaymentRepository extends DocumentRepository
{
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
}
