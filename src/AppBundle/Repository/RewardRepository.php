<?php

namespace AppBundle\Repository;

use AppBundle\Document\Reward;
use Doctrine\ODM\MongoDB\DocumentRepository;

class RewardRepository extends DocumentRepository
{
    public function getRewards()
    {
        return $this->findAll();
    }

    public function getSignUpBonus()
    {
        return $this->createQueryBuilder()
            ->field('isSignUpBonus')->equals(true)
            ->field('expiryDate')->gte(new \MongoDate())
            ->getQuery()
            ->getSingleResult();
    }
}
