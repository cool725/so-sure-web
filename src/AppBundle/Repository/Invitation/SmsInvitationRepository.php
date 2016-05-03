<?php

namespace AppBundle\Repository\Invitation;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;

class SmsInvitationRepository extends DocumentRepository
{
    use PhoneTrait;

    public function findDuplicate(Policy $policy, $mobile)
    {
        return $this->createQueryBuilder()
            ->field('policy')->references($policy)
            ->field('mobile')->equals($this->normalizeUkMobile($mobile))
            ->getQuery()
            ->execute();
    }
}
