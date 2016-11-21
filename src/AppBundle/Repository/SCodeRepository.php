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

    public function getLinkPrefix($prefix)
    {
        return $this->createQueryBuilder()
            ->field('shareLink')->equals(new \MongoRegex(sprintf('/%s.*/i', $prefix)))
            ->getQuery()
            ->execute();
    }
}
