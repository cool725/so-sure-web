<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;

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
        $qb->addOr($qb->expr()->field('closedDate')->gte($startWeek));
        $qb->addOr($qb->expr()->field('recordedDate')->gte($startWeek));
        $qb->addOr($qb->expr()->field('status')->in([Claim::STATUS_INREVIEW, Claim::STATUS_APPROVED]));

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
}
