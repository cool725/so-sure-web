<?php

namespace AppBundle\Repository\File;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;

class S3FileRepository extends DocumentRepository
{
    use DateTrait;

    public function getAllFiles(\DateTime $date, $type = null)
    {
        $startMonth = $this->startOfMonth($date);
        $nextMonth = $this->endOfMonth($date);

        $qb = $this->createQueryBuilder()
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth);

        if ($type) {
            $qb->field('fileType')->equals($type);
        }

        return $qb
            ->getQuery()
            ->execute();
    }

    public function getMonthlyFiles(\DateTime $date)
    {
        $startMonth = $this->startOfMonth($date);
        // include next month's file to help with reconsolilation
        $nextNextMonth = $this->endOfMonth($this->endOfMonth($date));

        return $this->createQueryBuilder()
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextNextMonth)
            ->getQuery()
            ->execute();
    }

    public function getAllFilesToDate(\DateTime $date)
    {
        $endMonth = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->field('date')->lt($endMonth)
            ->getQuery()
            ->execute();
    }

    public function getYearlyFilesToDate(\DateTime $date)
    {
        $startYear = $this->startOfYear($date);
        $endMonth = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->field('date')->gte($startYear)
            ->field('date')->lt($endMonth)
            ->getQuery()
            ->execute();
    }
}
