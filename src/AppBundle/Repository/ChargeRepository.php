<?php

namespace AppBundle\Repository;

use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;

class ChargeRepository extends DocumentRepository
{
    use DateTrait;

    public function findMonthly(
        \DateTime $date = null,
        $type = null,
        $excludeInvoiced = false,
        $affiliate = null
    ) {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $start = $this->startOfMonth($date);
        $end = $this->endOfMonth($date);

        $qb = $this->createQueryBuilder()
            ->field('createdDate')->gte($start)
            ->field('createdDate')->lt($end);

        if (is_array($type)) {
            $qb->field('type')->in($type);
        } elseif ($type) {
            $qb->field('type')->equals($type);
        }

        if ($excludeInvoiced) {
            $qb->field('invoice')->exists(false);
        }

        if ($type == 'affiliate' && $affiliate) {
            $qb->field('affiliate')->references($affiliate);
        }

        return $qb->sort('createdDate', 'asc')
            ->getQuery()
            ->execute();
    }

    /**
     * Finds the most recent charge that has been made in the name of a user, optionally of a given type.
     * @param User   $user is the user for whom we are looking for charges.
     * @param string $type is the type of charges that we are looking for, and if it is left null then we are looking
     *               for all charges.
     * @return Charge the most recent charge that matches the given type requirements or null if there is nothing.
     */
    public function findLastCharge($user, $type = null)
    {
        $chargeQuery = $this->createQueryBuilder('\Document\Charge')
            ->field('user')->equals($user);
        if ($type) {
            $chargeQuery->field('type')->equals($type);
        }
        $charges = $chargeQuery->sort('createdDate', 'DESC')
            ->limit(1)
            ->getQuery()->execute();
        if (count($charges) > 0) {
            return $charges->getSingleResult();
        } else {
            return null;
        }
    }
}
