<?php

namespace AppBundle\Repository;

use AppBundle\Document\PolicyTerms;
use Doctrine\ODM\MongoDB\DocumentRepository;

/**
 * Repository for policy terms.
 */
class PolicyTermsRepository extends DocumentRepository
{
    /**
     * Finds the latest policy terms.
     * @param boolean $aggregator is whether to find aggregator terms or normal terms.
     * @return PolicyTerms|null the latest policy terms or null if none exist.
     */
    public function findLatestTerms($aggregator = false)
    {
        $qb = $this->createQueryBuilder()->field("latest")->equals(true);
        if ($aggregator) {
            $qb->field("aggregator")->equals(true);
        } else {
            $qb->field("aggregator")->notEqual(true);
        }
        /** @var PolicyTerms|null $policyTerms */
        $policyTerms = $qb->getQuery()->getSingleResult();
        return $policyTerms;
    }
}
