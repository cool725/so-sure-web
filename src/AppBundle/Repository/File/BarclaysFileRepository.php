<?php

namespace AppBundle\Repository\File;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;

class BarclaysFileRepository extends DocumentRepository
{
    use DateTrait;

    public function getMonthBarclaysFiles(\DateTime $date)
    {
        $startMonth = $this->startOfMonth($date);
        $nextNextMonth = $this->endOfMonth($this->endOfMonth($date));

        return $this->createQueryBuilder()
            ->field('date')->gte($startMonth)
            ->field('date')->lt($nextNextMonth)
            ->getQuery()
            ->execute();
    }

    public function getAllBarclaysFilesToDate(\DateTime $date)
    {
        $endMonth = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->field('date')->lt($endMonth)
            ->getQuery()
            ->execute();
    }

    public function getYearBarclaysFilesToDate(\DateTime $date)
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
