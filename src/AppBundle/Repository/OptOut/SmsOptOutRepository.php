<?php

namespace AppBundle\Repository\OptOut;

use AppBundle\Document\OptOut\SmsOptOut;
use Doctrine\ODM\MongoDB\DocumentRepository;

class SmsOptOutRepository extends DocumentRepository
{
    public function findOptOut($mobile, $category)
    {
        $categoryAll = SmsOptOut::OPTOUT_CAT_ALL;
        return $this->createQueryBuilder()
            ->field('mobile')->equals($mobile)
            ->field('category')->in([$categoryAll, $category])
            ->getQuery()
            ->execute();
    }
}
