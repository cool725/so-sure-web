<?php

namespace AppBundle\Repository;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Document\User;
use AppBundle\Document\Charge;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;

class ChargeRepository extends DocumentRepository
{
    use DateTrait;

    /**
     * Gives you a list of charges matching the following conditions within the given month.
     * @param \DateTime        $date            is a date falling within the chosen month and defaults to now.
     * @param string|array     $type            is the type or types of charges to look for, and defaults to all.
     * @param boolean          $excludeInvoiced says whether or not charges with an invoice should be excluded.
     * @param AffiliateCompany $affiliate       is an optional affiliate company that all charges must have if present.
     * @return Cursor the result set of all the matching charges.
     */
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
     * Gives you the last charge made regarding a given user matching a given set of types, or null if there are no
     * charges matching the given user and types
     * @param User         $user is the user that the charge must be linked to.
     * @param string|array $type is a typename or an array of the possible types that the last found charge can be of.
     * @return Charge|null the charge found if there is one or null.
     */
    public function findLastByUser($user, $type = null)
    {
        $qb = $this->createQueryBuilder()->field("user")->references($user);
        if (is_array($type)) {
            $qb->field("type")->in($type);
        } elseif ($type) {
            $qb->field("type")->equals($type);
        }
        return $qb->sort("createdDate", "desc")->getQuery()->execute()->getSingleResult();
    }
}
