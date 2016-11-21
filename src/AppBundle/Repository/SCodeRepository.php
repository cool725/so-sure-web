<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;

class SCodeRepository extends DocumentRepository
{
    public function getCountForName($name)
    {
        return $this->createQueryBuilder()
            ->field('code')->equals(new \MongoRegex(sprintf('/%s.*/i', $name)))
            ->getQuery()
            ->execute()
            ->count();
    }
}
