<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\DateTrait;

class StatsRepository extends BaseDocumentRepository
{
    use DateTrait;

    public function getStatsByRange($start, $end)
    {
        $qb = $this->createQueryBuilder()
            ->field('date')->lt($end)
            ->field('date')->gte($start);

        return $qb
            ->sort('date', 'asc')
            ->getQuery()
            ->execute();
    }
}
