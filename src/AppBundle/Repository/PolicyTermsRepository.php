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
     * @return PolicyTerms|null the latest policy terms or null if none exist.
     */
    public function findLatestTerms()
    {
        $qb = $this->createQueryBuilder()->field("latest")->equals(true);
        /** @var PolicyTerms|null $policyTerms */
        $policyTerms = $qb->getQuery()->getSingleResult();
        return $policyTerms;
    }
}
