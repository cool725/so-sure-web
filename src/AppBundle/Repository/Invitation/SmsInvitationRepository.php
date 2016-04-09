<?php

namespace AppBundle\Repository\Invitation;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;

class SmsInvitationRepository extends DocumentRepository
{
    public function findDuplicate(Policy $policy, $mobile)
    {
        return $this->createQueryBuilder()
            ->field('policy')->references($policy)
            ->field('mobile')->equals($mobile)
            ->getQuery()
            ->execute();
    }
}
