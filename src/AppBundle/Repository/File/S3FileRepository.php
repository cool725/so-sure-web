<?php

namespace AppBundle\Repository\File;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;

class S3FileRepository extends DocumentRepository
{
    use DateTrait;

    public function getAllFiles(\DateTime $date)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth)
            ->getQuery()
            ->execute();
    }
}
