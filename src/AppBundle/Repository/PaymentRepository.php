<?php

namespace AppBundle\Repository;

use AppBundle\Classes\SoSure;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\DateTrait;

class PaymentRepository extends DocumentRepository
{
    use DateTrait;

    public function getAllPaymentsForExport(\DateTime $date)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);

        $qb = $this->createQueryBuilder()
            ->field('success')->equals(true)
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->field('type')->in([
                'judo',
                'bacs',
                'sosure',
                'chargeback',
                'potReward',
                'sosurePotReward',
                'policyDiscount',
                'debtCollection',
                'policyDiscountRefund',
            ])
            ->sort('date')
            ->sort('id')
            ->getQuery();

        //print_r($qb->getQuery());

        return $qb->execute();
    }

    public function getAllPaymentsForReport(\DateTime $date)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->field('type')->in(['judo', 'bacs', 'sosure'])
            ->getQuery()
            ->execute();
    }

    public function getAllPayments(\DateTime $date, $type = null)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);
        if (!$type) {
            $type = ['judo', 'bacs', 'sosure', 'chargeback', 'debtCollection'];
        } elseif (is_string($type)) {
            $type = [$type];
        }

        return $this->createQueryBuilder()
            ->field('success')->equals(true)
            ->field('policy')->notEqual(null)
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->field('type')->in($type)
            ->getQuery()
            ->execute();
    }
}
