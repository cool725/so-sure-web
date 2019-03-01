<?php

namespace AppBundle\Repository;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Payment\BacsPayment;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\DateTrait;

class PaymentRepository extends DocumentRepository
{
    use DateTrait;

    public function getAllPaymentsForExport(\DateTime $date, $extraMonth = false)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);
        if ($extraMonth) {
            $startMonth = $this->startOfMonth($date->sub(new \DateInterval('P1D')));
            $nextMonth = $this->endOfMonth($nextMonth);
        }

        $qb = $this->createQueryBuilder()
            ->field('success')->equals(true)
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->field('type')->in([
                'judo',
                'bacs',
                'bacsIndemnity',
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

    public function getAllPaymentsForReport(\DateTime $date, $judoOnly = false)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);

        $payments = ['judo', 'bacs', 'sosure'];
        if ($judoOnly) {
            $payments = ['judo'];
        }

        return $this->createQueryBuilder()
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->field('type')->in($payments)
            ->getQuery()
            ->execute();
    }

    public function getAllPayments(\DateTime $date, $type = null)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);
        if (!$type) {
            $type = ['judo', 'bacs', 'bacsIndemnity', 'sosure', 'chargeback', 'debtCollection'];
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

    public function getAllPendingCredits()
    {
        return $this->createQueryBuilder()
            ->field('status')->equals(BacsPayment::STATUS_PENDING)
            ->field('amount')->lt(0)
            ->getQuery()
            ->execute();
    }
}
