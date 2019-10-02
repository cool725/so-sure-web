<?php

namespace AppBundle\Service;

use Psr\Log\LoggerInterface;

/**
 * Calculates the right pricing for things.
 */
class PriceService
{
    const CACHE_KEY_FORMAT = "offer:%s:%s";

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger, DocumentManager $dm)
    {
        $this->logger = $logger;
        $this->dm = $dm;
    }

    /**
     * Gets the right price for a given phone policy.
     * @param PhonePolicy $policy is the policy we are getting the price for.
     * @param \DateTime   $date   is the date that this price should be correct for.
     * @return PhonePrice the price that should be paid.
     */
    public function phonePrice($policy, $date)
    {
        $user = $policy->getUser();
        $phone = $policy->getPhone();
        if (!$user) {
            throw new \IllegalArgumentException(sprintf(
                "Trying to get price for phone policy '%s' lacking user",
                $policy->getId()
            ));
        }
        if (!$phone) {
            throw new \InvalidArgumentException(sprintf(
                "Trying to get price for phone policy '%s' lacking phone",
                $policy->getId()
            ));
        }
        // Renewal price logic takes precedence.
        $isRenewal = someLogic();
        if ($isRenewal) {
            return $this->phoneRenewalPrice($policy);
        }
        // Offers are then checked and if present used, if there is no offer then normal scheduled price is used.
        $price = $this->phoneOfferPrice($policy);
        if ($price) {
            return $price;
        }
        return $policy->getPhone()->getCurrentPrice();
    }

    /**
     * Gets the right price for a given renewal phone policy.
     * @param PhonePolicy $policy is the policy to get the price for. It is assumed that a user and phone are present.
     * @param \DateTime   $date   is the date that this price must be correct for.
     * @return PhonePrice the price that has been calculated.
     */
    private function phoneRenewalPrice($policy, $date)
    {
        // In future there will be some more complex decision making logic regarding what price to use.
        // For the moment this will just return the current price.
        return $policy->getPhone()->getCurrentPrice($date);
    }

    /**
     * Gives all of the offers that are applicable to a given user.
     * @param User $user is the user to check for.
     * @return array containing all the offers.
     */
    private function getAllOffersForUser($user)
    {

    }

    /**
     * Adds an offer specifically to a user by caching it.
     * @param User  $user  is the user to add it to.
     * @param Offer $offer is the offer to add.
     * @param int   $time  is the time in seconds that the offer will last for.
     */
    private function addOfferToUser($user, $offer, $time)
    {

    }

    /**
     * Gives you the most eligible offer that should be used for the given phone and user.
     * @param User $user is the user we are checking for.
     * @param Phone $phone the phone it is about.
     * @param \DateTime $date is the date that we are checking about.
     * @return Offer|null the offer to use or null if there is not an offer.
     */
    private function getOfferForPhone($user, $phone, $date)
    {

    }
}
