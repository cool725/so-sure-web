<?php

namespace AppBundle\Repository\File;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;

class LloydsFileRepository extends DocumentRepository
{
    use DateTrait;

    public function getMonthLloydsFiles(\DateTime $date)
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

    public function getAllLloydsFilesToDate(\DateTime $date)
    {
        $endMonth = $this->endOfMonth($date);

        return $this->createQueryBuilder()
            ->field('date')->lt($endMonth)
            ->getQuery()
            ->execute();
    }

    public function getYearLloydsFilesToDate(\DateTime $date)
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
