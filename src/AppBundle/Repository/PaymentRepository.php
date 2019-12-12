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

    /**
     * Gives you all the payments for underwriter export.
     * @param \DateTime   $date        is the date which tells what month we are getting payments in.
     * @param boolean     $extraMonth  tells whether to expand the query to multiple months.
     * @param string|null $underwriter is the underwriter to restrict the query to, or null for all of them.
     * @return Cursor over all the payments for the export.
     */
    public function getAllPaymentsForExport(\DateTime $date, $extraMonth = false, $underwriter = null)
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
                'checkout',
                'bacs',
                'bacsIndemnity',
                'sosure',
                'chargeback',
                'potReward',
                'sosurePotReward',
                'policyDiscount',
                'debtCollection',
                'policyDiscountRefund'
            ]);
        if ($underwriter) {
            $qb->field('policy.policy_type')->equals($underwriter);
        }
        return $qb
            ->sort('date')
            ->sort('id')
            ->getQuery()->execute();
    }

    public function getAllPaymentsForReport(\DateTime $date, $judoOnly = false, $checkoutOnly = false)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);

        $payments = ['judo', 'checkout', 'bacs', 'sosure'];
        if ($judoOnly) {
            $payments = ['judo'];
        } elseif ($checkoutOnly) {
            $payments = ['checkout'];
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
            $type = ['judo', 'checkout', 'bacs', 'bacsIndemnity', 'sosure', 'chargeback', 'debtCollection'];
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
