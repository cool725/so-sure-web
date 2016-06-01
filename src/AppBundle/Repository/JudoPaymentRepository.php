<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\JudoPayment;

class JudoPaymentRepository extends DocumentRepository
{
    public function getAllPaymentsForExport($year, $month)
    {
        $date = new \DateTime(sprintf('%d-%d-01 00:00:00', $year, $month));
        $nextMonth = clone $date;
        $nextMonth->add(new \DateInterval('P1M'));

        return $this->createQueryBuilder()
            ->field('result')->equals(JudoPayment::RESULT_SUCCESS)
            ->field('date')->gte($date)
            ->field('date')->lt($nextMonth)
            ->getQuery()
            ->execute();
    }
}
