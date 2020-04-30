<?php

namespace AppBundle\Service;

use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Premium;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Offer;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Exception\IncorrectPriceException;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Calculates the right pricing for things.
 */
class PriceService
{
    use CurrencyTrait;

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
     * Calculates the premium that a user has chosen to pay for their policy by checking what their options were. Also
     * calculates their premium installments.
     * @param PhonePolicy $policy is the policy.
     * @param float       $amount is the amount they paid.
     * @param \DateTime   $date   is the date iergerg
     */
    public function phonePolicyDeterminePremium(PhonePolicy $policy, $amount, \DateTime $date)
    {
        $prices = $this->userPhonePriceStreams($policy->getUser(), $policy->getPhone(), $date);
        // TODO: Ideally should loop over prices that were valid in the last half hour even if not valid this moment.
        foreach ($prices as $stream => $price) {
            $installments = PhonePrice::streamInstallments($stream);
            $installmentPrice = $price->getYearlyPremiumPrice() / $installments;
            if ($this->areEqualToTwoDp($amount, $installmentPrice)) {
                $policy->setPremiumInstallments($installments);
                $policy->setPremium($price->createPremium());
                $this->dm->persist($policy);
                $this->dm->flush();
                return;
            }
        }
        throw new IncorrectPriceException(sprintf(
            "Policy '%s' paid invalid price of %f",
            $policy->getId(),
            $amount
        ));
    }

    /**
     * Gives you the premium that the given policy could pay in the given stream.
     * @param PhonePolicy $policy            is the policy we are looking at.
     * @param string      $stream            is the stream that they will be paying in.
     * @param float       $additionalPremium is some additional cost to add to the premium for some reason.
     * @param \DateTime   $date              is the date at which this premium is being calculated.
     * @return PhonePremium the premium for them.
     */
    public function getPhonePolicyPremium($policy, $stream, $additionalPremium, $date)
    {
        return $this->getPhonePremium($policy, $policy->getPhone(), $stream, $additionalPremium, $date);
    }

    /**
     * Gives you the premium for a phone
     * @param PhonePolicy $policy            is the policy we are looking at.
     * @param Phone       $phone             is the phone we want the premium for
     * @param string      $stream            is the stream that they will be paying in.
     * @param float       $additionalPremium is some additional cost to add to the premium for some reason.
     * @param \DateTime   $date              is the date at which this premium is being calculated.
     * @return PhonePremium the premium for them.
     */
    public function getPhonePremium($policy, $phone, $stream, $additionalPremium, $date)
    {
        $priceSource = $this->userPhonePriceSource($policy->getUser(), $phone, $stream, $date);
        $premium = $priceSource["price"]->createPremium($additionalPremium);
        $premium->setSource($priceSource["source"]);
        $premium->setStream($priceSource["price"]->getStream());
        if ($priceSource["source"] instanceof Offer) {
            $priceSource["source"]->addPolicy($policy);
            $premium->setSource(Premium::SOURCE_OFFER);
        } elseif ($priceSource["source"] instanceof Phone) {
            $premium->setSource(Premium::SOURCE_PHONE);
        }
        return $premium;
    }

    /**
     * Gives the passed policy the appropriate premium.
     * @param PhonePolicy $policy            is the policy to give a premium to.
     * @param string      $stream            is the price stream that they need.
     * @param float|null  $additionalPremium is an additional amount of cost to add to the overall price.
     * @param \DateTime   $date              is the date at which the price should be correct.
     */
    public function setPhonePolicyPremium($policy, $stream, $additionalPremium, $date)
    {
        $premium = $this->getPhonePolicyPremium($policy, $stream, $additionalPremium, $date);
        $policy->setPremium($premium);
        $this->dm->persist($policy);
        $this->dm->flush();
    }

    /**
     * Sets the premium for a renewal based on the logic that if the new price is more than the price on the old
     * policy, we just use the old premium again.
     * @param PhonePolicy $policy            is the policy to set the price for.
     * @param number      $additionalPremium is additional premium to add.
     * @param \DateTime   $date              is the current date for getting the currently valid price.
     */
    public function setPhonePolicyRenewalPremium($policy, $additionalPremium, $date)
    {
        $previous = $policy->getPreviousPolicy();
        if (!$previous) {
            throw new \InvalidArgumentException(sprintf(
                'Given policy %s cannot get a renewal price because it is not a renewal',
                $policy->getId()
            ));
        }
        $oldPremium = $previous->getPremium();
        $newPrice = $policy->getPhone()->getCurrentPhonePrice(
            PhonePrice::installmentsStream($policy->getPremiumInstallments()),
            $date
        );
        if ($newPrice->getGwp() > $oldPremium->getGwp()) {
            $policy->setPremium($oldPremium);
        } else {
            $this->setPhonePolicyPremium(
                $policy,
                PhonePrice::installmentsStream($policy->getPremiumInstallments()),
                $additionalPremium,
                $date
            );
        }
        $this->dm->persist($policy);
        $this->dm->flush();
    }
}
