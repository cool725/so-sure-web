<?php

namespace AppBundle\Repository\OptOut;

use AppBundle\Document\Opt\EmailOptOut;
use Doctrine\ODM\MongoDB\DocumentRepository;

class EmailOptOutRepository extends DocumentRepository
{
    public function isOptedOut($email, $category)
    {
        return count($this->findOptOut($email, $category)) > 0;
    }

    public function findOptOut($email, $category)
    {
        $categoryAll = EmailOptOut::OPTOUT_CAT_ALL;
        return $this->createQueryBuilder()
            ->field('email')->equals(mb_strtolower($email))
            ->field('categories')->in([$categoryAll, $category])
            ->getQuery()
            ->execute();
    }
}
