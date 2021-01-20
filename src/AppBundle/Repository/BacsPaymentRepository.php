<?php

namespace AppBundle\Repository;

use AppBundle\Document\Payment\BacsPayment;
use DateInterval;
use DateTime;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\MongoDBException;

class BacsPaymentRepository extends PaymentRepository
{
    use DateTrait;

    public function findPayments(\DateTime $month)
    {
        $startDay = $this->startOfMonth($month);
        $nextDay = $this->endOfMonth($month);

        return $this->createQueryBuilder()
            ->field('date')->gte($startDay)
            ->field('date')->lt($nextDay)
            ->sort('date', 'desc')
            ->sort('serialNumber', 'desc')
            ->getQuery()
            ->execute();
    }

    public function findUnprocessedPaymentsOlderThanDays(array $statuses, $businessDays = 1)
    {
        $date = new \DateTime('now');
        $prevStartOfBusinessDay = $this->subBusinessDays($date, $businessDays);
        $prevStartOfBusinessDay = $this->startOfDay($prevStartOfBusinessDay);

        return $this->createQueryBuilder()
            ->field('date')->lt($prevStartOfBusinessDay)
            ->field('status')->in($statuses)
            ->sort('date', 'desc')
            ->getQuery()
            ->execute();
    }

    public function findSubmittedPayments(\DateTime $date = null)
    {
        if (!$date) {
            $date = $this->now();
        }
        return $this->createQueryBuilder()
            ->field('status')->equals(BacsPayment::STATUS_SUBMITTED)
            ->field('bacsReversedDate')->lte($date)
            ->getQuery()
            ->execute();
    }

    public function findPaymentsIncludingPreviousNextMonth(\DateTime $month)
    {
        $previousMonth = clone $month;
        $previousMonth = $previousMonth->sub(new DateInterval('P1M'));
        $startDay = $this->startOfMonth($previousMonth);
        $nextDay = $this->endOfMonth($this->endOfMonth($month));

        return $this->createQueryBuilder()
            ->field('date')->gte($startDay)
            ->field('date')->lt($nextDay)
            ->sort('date', 'desc')
            ->sort('serialNumber', 'desc')
            ->getQuery()
            ->execute();
    }

    /**
     * Finds all backpayments that are not linked to a payment.
     * @return array of bacs backpayments.
     */
    public function findUnlinkedReversals()
    {
        return $this->createQueryBuilder()
            ->field('amount')->lt(0)
            ->field('reverses')->equals(null)
            ->getQuery()
            ->execute();
    }

    /**
     * Takes a reversing bacs payment and find the payment that it is reversing.
     * @param BacsPayment $payment is the reversing payment.
     * @return BacsPayment|null the payment that it is likely a reversal of, or null if nothing is found.
     */
    public function findReversed($payment)
    {
        if ($payment->getReverses()) {
            return $payment->getReverses();
        }
        /** @var BacsPayment|null $reversed */
        $reversed = $this->findOneBy([
            '_id' => ['$ne' => $payment->getId()],
            'reversedBy' => null,
            'date' => [
                '$gte' => $this->addDays($payment->getDate(), 0 - BacsPayment::DAYS_REVERSE),
                '$lte' => $payment->getDate()
            ],
            'policy.id' => $payment->getPolicy()->getId(),
            'amount' => 0 - $payment->getAmount()
        ]);
        return $reversed;
    }

    /**
     * Finds the payments that should go into the bacs payments report which is all payments that have a higher effect
     * date than three weeks ago or they are pending, generated, or submitted with any date.
     * @param DateTime $date is the date which should be thought of as the current date.
     * @return array containing all the found payments.
     */
    public function findBacsPaymentsForReport(DateTime $date)
    {
        $cutoff = (clone $date)->sub(new DateInterval('P3W'));
        try {
            $query = $this->createQueryBuilder();
            $query->addOr($query->expr()->field('date')->gt($cutoff));
            $query->addOr($query->expr()->field('status')->in([
                BacsPayment::STATUS_PENDING,
                BacsPayment::STATUS_GENERATED,
                BacsPayment::STATUS_SUBMITTED
            ]));
            return $query->getQuery()->execute();
        } catch (MongoDBException $e) {
            return [];
        }
    }

    /**
     * Finds bacs payments that have not been reverted which have got a covering checkout payment which are ready to be
     * reverted.
     * @param \DateTime $date is the date which is to be considered now for the sake of finding which bacs payments are
     *                        ready to go.
     * @return Cursor over the found results.
     */
    public function findReadyCoveredPayments(DateTime $date)
    {
        return $this->createQueryBuilder()
            ->field('coveredBy')->exists(true)
            ->field('success')->equals(true)
            ->field('bacsReversedDate')->lt($date)
            ->field('reversedBy')->exists(false)
            ->field('coveringPaymentRefunded')->notEqual(true)
            ->getQuery()
            ->execute();
    }
}
