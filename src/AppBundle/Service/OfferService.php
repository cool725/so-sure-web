<?php
namespace AppBundle\Service;

use AppBundle\Document\Offer;
use AppBundle\Document\Phone;
use Symfony\Component\Finder\Finder;
use Psr\Log\LoggerInterface;

/**
 * Implements functionality around the managment of offers.
 */
class OfferService
{
    const CACHE_KEY_FORMAT = "offer:%s:%s";

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Gives all of the offers that are applicable to a given user.
     * @param User $user is the user to check for.
     * @return array containing all the offers.
     */
    public function getAllOffersForUser($user)
    {

    }

    /**
     * Adds an offer specifically to a user by caching it.
     * @param User  $user  is the user to add it to.
     * @param Offer $offer is the offer to add.
     * @param int   $time  is the time in seconds that the offer will last for.
     */
    public function addOfferToUser($user, $offer, $time)
    {

    }

    /**
     * Gives you the most eligible offer that should be used for the given phone and user.
     * @param User $user is the user we are checking for.
     * @param Phone $phone the phone it is about.
     * @param \DateTime $date is the date that we are checking about.
     * @return Offer|null the offer to use or null if there is not an offer.
     */
    public function getOfferForPhone()
    {

    }
}
