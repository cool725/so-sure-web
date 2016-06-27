<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\DateTrait;

class JudoPaymentRepository extends DocumentRepository
{
    use DateTrait;

    public function getAllPaymentsForExport(\DateTime $date)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->field('result')->equals(JudoPayment::RESULT_SUCCESS)
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->getQuery()
            ->execute();
    }
}
