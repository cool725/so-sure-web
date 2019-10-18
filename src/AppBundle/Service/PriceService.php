<?php

namespace AppBundle\Service;

use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Offer;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Calculates the right pricing for things.
 */
class PriceService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /**
     * Builds the service and injects dependencies.
     * @param LoggerInterface $logger is used for logging.
     * @param DocumentManager $dm     is used for access to database.
     */
    public function __construct(LoggerInterface $logger, DocumentManager $dm)
    {
        $this->logger = $logger;
        $this->dm = $dm;
    }

    /**
     * Gets a price for a given phone in a given stream for a given user, assuming this is a new purchase and not
     * a refund.
     * @param User|null $user   is the user who will be paying the price.
     * @param Phone     $phone  is the phone that they will be paying for.
     * @param string    $stream is whether they are going to be paying monthly or yearly (Don't pass ALL or ANY).
     * @param \DateTime $date   is the date at which the purchase is occuring.
     * @return PhonePrice|null the price that they should pay under these conditions, or null if there is no applicable
     *                         price.
     */
    public function userPhonePrice($user, $phone, $stream, $date)
    {
        // first check for an offer price.
        $offer = $user ? $user->getApplicableOffer($phone, $stream, $date) : null;
        if ($offer) {
            // TODO: price should be made unique and branded with it's origin.
            return $offer->getPrice();
        }
        // if there is no applicable offer price we use the appropriate normal price.
        return $phone->getCurrentPhonePrice($stream, $date);
    }

    /**
     * Finds the right phone price for a user in every stream so that they can see their options. If the user is null
     * then it just gives the most up to date general pricing.
     * @param User|null $user  is the user we are enquiring about.
     * @param Phone     $phone is the phone we are enquiring about.
     * @param \DateTime $date  is the date at which this must be valid.
     * @return array with keys on each real price stream and offers or null under each.
     */
    public function userPhonePriceStreams($user, $phone, $date)
    {
        $prices = [];
        foreach (PhonePrice::STREAMS as $stream) {
            $prices[$stream] = $this->userPhonePrice($user, $phone, $stream, $date);
        }
        return $prices;
    }

    /**
     * Gives the passed policy the appropriate premium.
     * @param Phone Policy $policy is the policy to give a premium to.
     * @param string $stream is the price stream that they need.
     * @param \DateTime $date is the date at which the price should be correct.
     */
    public function policyPhonePremium($policy, $stream, $date)
    {
        $premium = $this->userPhonePrice($policy->getUser(), $policy->getPhone(), $stream, $date);
        // TODO: brand it.
        $policy->setPremium($premium);
        $this->dm->persist($policy);
        $this->dm->flush();
    }

    /**
     * Finds the price that a given renewal policy should pay.
     * @param Policy $policy is the policy that will have this price potentially.
     * @param \DateTime $date is the date at which the policy shall start.
     * @return PhonePrice the price that the policy should pay.
     */
    public function renewalPhonePrice($policy, $date)
    {
        if (!$policy->getPreviousPolicy()) {
            throw new \InvalidArgumentException(sprintf("Given policy '%s' is not a renewal", $policy->getId()));
        }
        // TODO: price should be made unique and branded with it's origin.
        if ($policy->hasMonetaryClaimed(true)) {
            return $policy->getPhone()->getCurrentPhonePrice($policy->getStream(), $date);
        } else {
            // TODO: it's meant to return a price not a premium you idiiot.
            return $policy->getPreviousPolicy()->getPremium();
        }
    }
}
