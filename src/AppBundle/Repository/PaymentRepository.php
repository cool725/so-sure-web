<?php

namespace AppBundle\Repository;

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

        return $this->createQueryBuilder()
            ->field('success')->equals(true)
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->field('type')->in([
                'judo',
                'bacs',
                'sosure',
                'chargeback',
                'potReward',
                'policyDiscount',
                'debtCollection',
                'policyDiscountRefund',
            ])
            ->sort('date')
            ->getQuery()
            ->execute();
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
