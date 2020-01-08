<?php

namespace AppBundle\Repository;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;

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

    /**
     * Gives you all successful payments in a given month and optionally of a given type and with a given underwriter.
     * @param \DateTime         $date        is a date within the month in which we are looking for payments.
     * @param string|array|null $type        is the type of payment to look for or null for any, and an array for a set
     *                                       of types that it can be in.
     * @param string|null       $policyType  is the type of the policy upon which we are looking for payments.
     * @return array containing all of these payments.
     */
    public function getAllPayments(\DateTime $date, $type = null, $policyType = null)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);
        if (!$type) {
            $type = ['judo', 'checkout', 'bacs', 'bacsIndemnity', 'sosure', 'chargeback', 'debtCollection'];
        } elseif (is_string($type)) {
            $type = [$type];
        }
        $qb = $this->createQueryBuilder()
            ->field('success')->equals(true)
            ->field('policy')->notEqual(null)
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->field('type')->in($type);
        if ($underwriter) {
            $qb->field('policy.policy_type')->equals($underwriter);
        }
        return $qb->getQuery()->execute();
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
