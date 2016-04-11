<?php

namespace AppBundle\Repository\Invitation;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;

class EmailInvitationRepository extends DocumentRepository
{
    public function findDuplicate(Policy $policy, $email)
    {
        return $this->createQueryBuilder()
            ->field('policy')->references($policy)
            ->field('email')->equals(strtolower($email))
            ->getQuery()
            ->execute();
    }
}
