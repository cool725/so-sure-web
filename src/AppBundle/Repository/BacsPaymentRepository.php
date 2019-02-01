<?php

namespace AppBundle\Repository;

use AppBundle\Document\Payment\BacsPayment;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\DateTrait;

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

    public function findSubmittedPayments(\DateTime $date)
    {
        return $this->createQueryBuilder()
            ->field('status')->equals(BacsPayment::STATUS_SUBMITTED)
            ->field('bacsReversedDate')->lte($date)
            ->getQuery()
            ->execute();
    }

    public function findPaymentsIncludingPreviousNextMonth(\DateTime $month)
    {
        $previousMonth = clone $month;
        $previousMonth = $previousMonth->sub(new \DateInterval('P1M'));
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
}
