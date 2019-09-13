<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\Cursor;

/**
 * Makes queries for finding offers.
 */
class OfferRepository extends DocumentRepository
{
    /**
     * Gets all offers that are in effect at a given time.
     * @param \DateTime $date is the date and time that we are looking at.
     * @return Cursor over all the found offers.
     */
    public function getAllCurrent(\DateTime $date)
    {
        return $this->createQueryBuilder()
            ->field('validFrom')->lte($now)
            ->field('validTo')->gte($now)
            ->getQuery()
            ->execute()
            ->count();
    }

    /**
     * Gets all offers relating to a given phone model.
     * @param Phone $phone is the phone model to find offers for.
     * @return Cursor over all the offers.
     */
    public function getAllByPhone($phone)
    {
        return $this->createQueryBuilder()
            ->field('phone')->references($phone)
            ->getQuery()
            ->execute();
    }
}
