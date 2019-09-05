<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;

class OfferRepository extends DocumentRepository
{
    public function getAllCurrent()
    {
        $now = new \DateTime();
        return $this->createQueryBuilder()
            ->field('validFrom')->lte($now)
            ->field('validTo')->gte($now)
            ->getQuery()
            ->execute()
            ->count();
    }

    public function getAllByPhone($phone)
    {
        return $this->createQueryBuilder()
            ->field('phone')->references($phone)
            ->getQuery()
            ->execute();
    }
}
