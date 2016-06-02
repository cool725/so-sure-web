<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;

class ClaimRepository extends DocumentRepository
{
    public function getAllClaimsForExport(\DateTime $date)
    {
        \AppBundle\Classes\NoOp::noOp([$date]);
        // TODO: state open or closed date within x weeks of date
        return $this->createQueryBuilder()
            ->getQuery()
            ->execute();
    }
}
