<?php

namespace AppBundle\Repository\Invitation;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;

class InvitationRepository extends DocumentRepository
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

        return $qb->getQuery()
            ->execute()
            ->count();
    }
}
