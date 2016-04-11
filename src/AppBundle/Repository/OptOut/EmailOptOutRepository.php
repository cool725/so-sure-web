<?php

namespace AppBundle\Repository\OptOut;

use AppBundle\Document\OptOut\EmailOptOut;
use Doctrine\ODM\MongoDB\DocumentRepository;

class EmailOptOutRepository extends DocumentRepository
{
    public function findOptOut($email, $category)
    {
        $categoryAll = EmailOptOut::OPTOUT_CAT_ALL;
        return $this->createQueryBuilder()
            ->field('email')->equals(strtolower($email))
            ->field('category')->in([$categoryAll, $category])
            ->getQuery()
            ->execute();
    }
}
