<?php

namespace AppBundle\Service;

use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Premium;
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
     * Gives you the right price for the given user and phone and also the source of the price in an array.
     * @param User|null $user   is the user to get the price for.
     * @param Phone     $phone  is the phone that they are getting a policy for.
     * @param string    $stream is the price stream that they are paying in.
     * @param \DateTime $date   is the date at which they are purchasing.
     * @return array containing the price and the source of the price.
     */
    public function userPhonePriceSource($user, $phone, $stream, $date)
    {
        // Offer price takes first precedence.
        $offer = $user ? $user->getApplicableOffer($phone, $stream, $date) : null;
        if ($offer) {
            return ["price" => $offer->getPrice(), "source" => $offer];
        }
        // Default phone price.
        return ["price" => $phone->getCurrentPhonePrice($stream, $date), "source" => $phone];
    }

    /**
     * Gets a price for a given phone in a given stream for a given user, assuming this is a new purchase and not
     * a renewal.
     * @param User|null $user   is the user who will be paying the price.
     * @param Phone     $phone  is the phone that they will be paying for.
     * @param string    $stream is whether they are going to be paying monthly or yearly (Don't pass ALL or ANY).
     * @param \DateTime $date   is the date at which the purchase is occuring.
     * @return PhonePrice|null the price that they should pay under these conditions, or null if there is no applicable
     *                         price.
     */
    public function userPhonePrice($user, $phone, $stream, $date)
    {
        return $this->userPhonePriceSource($user, $phone, $stream, $date)["price"];
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
     * @param PhonePolicy $policy            is the policy to give a premium to.
     * @param string      $stream            is the price stream that they need.
     * @param float|null  $additionalPremium is an additional amount of cost to add to the overall price.
     * @param \DateTime   $date              is the date at which the price should be correct.
     */
    public function policySetPhonePremium($policy, $stream, $additionalPremium, $date)
    {
        $priceSource = $this->userPhonePriceSource($policy->getUser(), $policy->getPhone(), $stream, $date);
        $premium = $priceSource["price"]->createPremium($additionalPremium);
        $premium->setSource($priceSource["source"]);
        $premium->setStream($priceSource["price"]->getStream());
        if ($priceSource["source"] instanceof Offer) {
            $priceSource["source"]->addPolicy($policy);
            $premium->setSource(Premium::SOURCE_OFFER);
        } elseif ($priceSource["source"] instanceof Phone) {
            $premium->setSource(Premium::SOURCE_PHONE);
        }
        $policy->setPremium($premium);
        $this->dm->persist($policy);
        $this->dm->flush();
    }

    /**
     * Finds the price that a given renewal policy should pay.
     * @param PhonePolicy $policy is the policy that will have this price potentially.
     * @param \DateTime   $date   is the date at which the policy shall start.
     * @return PhonePrice|null the price that the policy should pay.
     */
    public function renewalPhonePrice($policy, $date)
    {
        if (!$policy->getPreviousPolicy()) {
            throw new \InvalidArgumentException(sprintf("Given policy '%s' is not a renewal", $policy->getId()));
        }
        if ($policy->hasMonetaryClaimed(true)) {
            return $policy->getPhone()->getCurrentPhonePrice(
                PhonePrice::installmentsStream($policy->getPremiumInstallments()),
                $date
            );
        } else {
            // TODO: should be more like the apply premium function so that renewal price can be old price which at
            //       this point is a premium not a price.
            return $policy->getPhone()->getCurrentPhonePrice(
                PhonePrice::installmentsStream($policy->getPremiumInstallments()),
                $date
            );
        }
    }
}
