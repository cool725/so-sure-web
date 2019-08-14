<?php

namespace AppBundle\Repository\File;

use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;

class S3FileRepository extends DocumentRepository
{
    use DateTrait;

    public function getAllFiles(\DateTime $date, $type = null, $includePrevMonth = false)
    {
        $startMonth = $this->startOfMonth($date);
        if ($includePrevMonth) {
            $startMonth = $startMonth->sub(new \DateInterval('P1M'));
        }
        $nextMonth = $this->endOfMonth($date);

        $qb = $this->createQueryBuilder()
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextMonth);

        if ($type) {
            $qb->field('fileType')->equals($type);
        }

        return $qb
            ->sort('date', 'desc')
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

    /**
     * Gives all the files that have been processed this month.
     * @param \DateTime $date is a date within the month of interest.
     * @return Cursor over all the files.
     */
    public function getMonthlyProcessedFiles(\DateTime $date)
    {
        $start = $this->startOfMonth($date);
        $end = $this->endOfMonth($date);
        return $this->createQueryBuilder()
            ->field("metadata.processing-date")->gte($start)
            ->field("metadata.processing-date")->lt($end)
            ->getQuery()
            ->execute();
    }

    public function getAllFilesToDate(\DateTime $date = null, string $type = null)
    {
        $endMonth = $this->endOfMonth($date ?: (new \DateTime()));
        $qb = $this->createQueryBuilder()->field('date')->lt($endMonth);
        if ($type) {
            $qb->field('fileType')->equals($type);
        }
        return $qb->getQuery()->execute();
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
