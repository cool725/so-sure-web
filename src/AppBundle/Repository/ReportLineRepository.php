<?php

namespace AppBundle\Repository;

use AppBundle\Document\ReportLine;
use Doctrine\ODM\MongoDB\MongoDBException;

/**
 * Repository for report lines.
 */
class ReportLineRepository extends BaseDocumentRepository
{
    /**
     * Deletes all report lines that reference the given policy.
     * @param Policy $policy for whom all report lines will be eliminated.
     */
    public function deleteAllForPolicy($policy)
    {
        $this->createQueryBuilder()
            ->remove()
            ->field('policy')->references($policy)
            ->getQuery()
            ->execute();
    }

    /**
     * Counts all the report lines there are for a given type of report.
     * @param string $type is the report line type.
     * @return number the number of report lines of the type.
     */
    public function countForType($type)
    {
        return $this->createQueryBuilder()
            ->field('type')->equals($type)
            ->getQuery()
            ->execute()
            ->count();
    }
}
