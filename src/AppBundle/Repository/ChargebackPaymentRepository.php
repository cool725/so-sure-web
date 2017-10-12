<?php

namespace AppBundle\Repository;

use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;

class ChargebackPaymentRepository extends PaymentRepository
{
    public function findUnassigned($amount = null)
    {
        $qb = $this->createQueryBuilder()
            ->field('policy')->equals(null);
        if ($amount) {
            $qb->field('amount')->equals($amount);
        }

        return $qb;
    }
}
