<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;

class UserRepository extends DocumentRepository
{
    public function existsUser($email, $facebookId = null)
    {
        $qb = $this->createQueryBuilder();
        $qb->addOr($qb->expr()->field('emailCanonical')->equals(strtolower($email)));
        if ($facebookId) {
            $qb->addOr($qb->expr()->field('facebookId')->equals(trim($facebookId)));
        }

        return $qb
            ->getQuery()
            ->execute()
            ->count() > 0;
    }
}
