<?php

namespace AppBundle\Repository\OptOut;

use AppBundle\Document\Opt\SmsOptOut;
use Doctrine\ODM\MongoDB\DocumentRepository;

class SmsOptOutRepository extends DocumentRepository
{
    public function isOptedOut($mobile, $category)
    {
        return count($this->findOptOut($mobile, $category)) > 0;
    }

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
