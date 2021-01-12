<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;

class ClaimRepository extends DocumentRepository
{
    /**
     * Gives you all claims for underwriter export.
     * @param string|null $underwriter is the underwriter to find claims for. null to ignore.
     * @return Cursor over the claims.
     */
    public function getAllClaimsForExport($underwriter = null)
    {
        $qb = $this->createQueryBuilder();
        $qb->field('notificationDate')->notEqual(null);
        $qb->field('status')->notIn([Claim::STATUS_FNOL, Claim::STATUS_SUBMITTED, null]);
        $qb->field('number')->notEqual(null);
        if ($underwriter) {
            $qb->field('policy.policy_type')->equals($underwriter);
        }
        $qb->sort('underwriterLastUpdated', 'desc');
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

    public function findPostClaims($start)
    {
        $qb = $this->createQueryBuilder()
                   ->field('replacementReceivedDate')->gte($start)
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
