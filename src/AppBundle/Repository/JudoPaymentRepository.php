<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\JudoPayment;

class JudoPaymentRepository extends DocumentRepository
{
    public function getAllPaymentsForExport(\DateTime $date)
    {
        $startMonth = new \DateTime(sprintf('%d-%d-01 00:00:00', $date->format('Y'), $date->format('m')));
        $nextMonth = clone $startMonth;
        $nextMonth->add(new \DateInterval('P1M'));

        return $this->createQueryBuilder()
            ->field('result')->equals(JudoPayment::RESULT_SUCCESS)
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->getQuery()
            ->execute();
    }
}
