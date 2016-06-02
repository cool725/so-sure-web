<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;

class ClaimRepository extends DocumentRepository
{
    public function getAllClaimsForExport(\DateTime $date)
    {
        return $this->createQueryBuilder()
            ->getQuery()
            ->execute();
    }
}
