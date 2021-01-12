<?php

namespace AppBundle\Repository;

use AppBundle\Document\ReportLine;
use Doctrine\ODM\MongoDB\MongoDBException;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;

/**
 * Repository for report lines.
 */
class ReportLineRepository extends BaseDocumentRepository
{
    /**
     * Counts all the report lines there are for a given type of report.
     * @param string $type is the report line type.
     * @return number the number of report lines of the type.
     */
    public function countForType($type)
    {
        return $this->createQueryBuilder()
            ->field('report')->equals($type)
            ->getQuery()
            ->execute()
            ->count();
    }

    /**
     * Tells you the highest and lowest numeric ids for a given type of report line.
     * @param string $type is the type of report to look for.
     * @return array containing first the lowest and then the highest id.
     */
    public function getBoundsForType($type)
    {
        /** @var ReportLine $min */
        $min = $this->createQueryBuilder()
            ->field('report')->equals($type)
            ->sort('number', 'asc')
            ->limit(1)
            ->getQuery()
            ->getSingleResult();
        /** @var ReportLine $max */
        $max = $this->createQueryBuilder()
            ->field('report')->equals($type)
            ->sort('number', 'desc')
            ->limit(1)
            ->getQuery()
            ->getSingleResult();
        return [
            $min ? $min->getNumber() : 0,
            $max ? $max->getNumber() : 0
        ];
    }

    /**
     * Finds all the report lines with numeric ids in the given range for the given type of report line.
     * @param string $type is the type of report line.
     * @param number $min  is the minimum inclusive numeric id to find.
     * @param number $max  is the maximum exclusive numeric id.
     * @return Cursor over the found ids.
     */
    public function findInBounds($type, $min, $max)
    {
        $qb = $this->createQueryBuilder()->field('report')->equals($type);
        $qb->addAnd($qb->expr()->field('number')->gte($min));
        $qb->addAnd($qb->expr()->field('number')->lt($max));
        return $qb->getQuery()->execute();
    }
}
