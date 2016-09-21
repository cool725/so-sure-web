<?php

namespace AppBundle\Repository\Invitation;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Repository\BaseDocumentRepository;

class InvitationRepository extends BaseDocumentRepository
{
    public function count(\DateTime $start = null, \DateTime $end = null)
    {
        $qb = $this->createQueryBuilder();

        if ($start) {
            $qb->field('created')->gte($start);
        }
        if ($end) {
            $qb->field('created')->lte($end);
        }
        if ($this->excludedPolicyIds) {
            $this->addExcludedPolicyQuery($qb, 'policy.$id');
        }

        return $qb->getQuery()
            ->execute()
            ->count();
    }
}
