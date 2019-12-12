<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;

class ClaimRepository extends DocumentRepository
{
    public function getAllClaimsForExport(\DateTime $date, $days = null)
    {
        if (!$days) {
            $days = 7;
        }
        $endWeek = new \DateTime(sprintf(
            '%d-%d-%d 00:00:00',
            $date->format('Y'),
            $date->format('m'),
            $date->format('d')
        ));
        $startWeek = clone $endWeek;
        $startWeek->sub(new \DateInterval(sprintf('P%dD', $days)));

        $qb = $this->createQueryBuilder();
        $qb->field('notificationDate')->notEqual(null);
        $qb->field('status')->notIn([Claim::STATUS_FNOL, Claim::STATUS_SUBMITTED, null]);
        $qb->field('number')->notEqual(null);
        $qb->field('policy.policy_type')->equals('salva-phone');
        $qb->sort('underwriterLastUpdated', 'desc');

        /*
         * Aleks requested to see all claims for the time being...
        $qb->addOr($qb->expr()->field('closedDate')->gte($startWeek));
        $qb->addOr($qb->expr()->field('recordedDate')->gte($startWeek));
        $qb->addOr($qb->expr()->field('status')->in([Claim::STATUS_INREVIEW, Claim::STATUS_APPROVED]));
        */
        return $qb->getQuery()->execute();
    }

    public function findFNOLClaims($start, $end)
    {
        $qb = $this->createQueryBuilder()
            ->field('notificationDate')->gte($start)
            ->field('notificationDate')->lt($end)
        ;

        return $qb->getQuery()->execute();
    }

    public function findApprovedClaims($start, $end)
    {
        $qb = $this->createQueryBuilder()
            ->field('approvedDate')->gte($start)
            ->field('approvedDate')->lt($end)
        ;

        return $qb->getQuery()->execute();
    }

    public function findClosedClaims($start, $end)
    {
        $qb = $this->createQueryBuilder()
            ->field('closedDate')->gte($start)
            ->field('closedDate')->lt($end)
        ;

        return $qb->getQuery()->execute();
    }

    public function findOutstanding($handlingTeam = null)
    {
        $qb = $this->createQueryBuilder()
            ->field('status')->in([Claim::STATUS_INREVIEW, Claim::STATUS_APPROVED]);

        if ($handlingTeam) {
            $qb = $qb->field('handlingTeam')->equals($handlingTeam);
        }

        return $qb->getQuery()->execute();
    }

    public function findByPolicy(Policy $policy)
    {
        return $this->createQueryBuilder()->field('policy')->references($policy);
    }

    public function findMissingReceivedDate()
    {
        return $this->createQueryBuilder()
            // exclude withdrawn/declined claim in case status changes
            ->field('status')->in([Claim::STATUS_INREVIEW, Claim::STATUS_APPROVED, Claim::STATUS_SETTLED])
            ->field('replacementReceivedDate')->equals(null)
            ->field('replacementImei')->notEqual(null)
            ->getQuery()->execute();
    }
    
    public function findSettledUnprocessed()
    {
        return $this->createQueryBuilder()
            ->field('status')->equals(Claim::STATUS_SETTLED)
            ->field('processed')->in([null, false])
            ->getQuery()->execute();
    }

    public function findClaimByDetails($id = null, $number = null)
    {
        $qb = $this->createQueryBuilder();

        if ($id) {
            $qb->addAnd($qb->expr()->field('_id')->equals($id));
        }

        if ($number) {
            $qb->addAnd($qb->expr()->field('number')->equals($number));
        }

        if ($qb->getQuery()->count() > 1) {
            throw new \Exception(sprintf("Query returned more than one result for claim. %s %s", $id, $number));
        }

        return $qb->getQuery()->execute()->getSingleResult();
    }
}
