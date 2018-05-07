<?php

namespace AppBundle\Repository\File;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;

class BarclaysStatementFileRepository extends DocumentRepository
{
    use DateTrait;

    public function getMonthBarclaysStatementFiles(\DateTime $date)
    {
        $startMonth = $this->startOfMonth($date);
        $nextNextMonth = $this->endOfMonth($this->endOfMonth($date));

        return $this->createQueryBuilder()
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextNextMonth)
            ->getQuery()
            ->execute();
    }
}
