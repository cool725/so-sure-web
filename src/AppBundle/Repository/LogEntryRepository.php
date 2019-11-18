<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\LogEntry;
use AppBundle\Document\Policy;

class LogEntryRepository extends DocumentRepository
{
    /**
     * Finds the most recent log entry where the given policy entered it's current status.
     * @param Policy $policy is the policy we are checking on.
     * @return LogEntry|null the found log entry or null when nothing is found.
     */
    public function findRecentStatus($policy)
    {
        /** @var LogEntry $entry */
        $entry =  $this->createQueryBuilder()
            ->field('objectId')->equals($policy->getId())
            ->field('data.status')->equals($policy->getStatus())
            ->sort('loggedAt', 'desc')
            ->getQuery()
            ->getSingleResult();
        return $entry;
    }
}
