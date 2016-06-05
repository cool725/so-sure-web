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

    public function findSystemReinvitations(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $threeDays = clone $date;
        $threeDays->sub(new \DateInterval('P3D'));

        // reinvitedCount must be 0, so user never reinvited
        // and at least 3 days have pasted since the original invite
        // and in case rules change, the nextReinvited date has past
        return $this->createQueryBuilder()
            ->field('created')->lte($threeDays)
            ->field('nextReinvited')->lte($date)
            ->field('reinvitedCount')->equals(0)
            ->field('accepted')->equals(null)
            ->field('cancelled')->equals(null)
            ->field('rejected')->equals(null)
            ->getQuery()
            ->execute();
    }
}
