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

    /**
     * Gives you a connection bonus if there is one. If there are two you only get one and the one you get is arbitrary
     * because you can't use two anyway.
     * @param \DateTime $date is the date at which they must have not expired.
     * @return Reward|null the bonus if it exists.
     */
    public function getConnectionBonus($date)
    {
        $qb = $this->createQueryBuilder();
        $qb->addAnd($qb->expr()->field("isConnectionBonus")->equals(true));
        $qb->addOr($qb->expr()->field("expiryDate")->exists(false));
        $qb->addOr($qb->expr()->field("expiryDate")->gte($date));
        /** @var Reward|null $reward */
        $reward =  $qb->getQuery()->getSingleResult();
        return $reward;
    }
}
