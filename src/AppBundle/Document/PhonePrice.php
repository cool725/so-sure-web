<?php

namespace AppBundle\Document;

use AppBundle\Document\Excess\PhoneExcess;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\EmbeddedDocument
 */
class PhonePrice extends Price
{
    use CurrencyTrait;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Excess\PhoneExcess")
     * @Gedmo\Versioned
     * @var PhoneExcess|null
     */
    protected $picSureExcess;

    public function setPicSureExcess(PhoneExcess $phoneExcess)
    {
        $this->picSureExcess = $phoneExcess;
    }

    public function getPicSureExcess()
    {
        return $this->picSureExcess;
    }

    public function getMaxPot($isPromoLaunch = false)
    {
        if ($isPromoLaunch) {
            return $this->toTwoDp($this->getYearlyPremiumPrice());
        } else {
            return $this->toTwoDp($this->getYearlyPremiumPrice() * 0.8);
        }
    }

    public function getMaxConnections($promoAddition = 0, $isPromoLaunch = false)
    {
        return (int) ceil($this->getMaxPot($isPromoLaunch) / $this->getInitialConnectionValue($promoAddition));
    }

    public function getInitialConnectionValue($promoAddition = 0)
    {
        return PhonePolicy::STANDARD_VALUE + $promoAddition;
    }

    public function createPremium($additionalGwp = null, \DateTime $date = null)
    {
        $premium = new PhonePremium();
        $this->populatePremium($premium, $additionalGwp, $date);
        $premium->setPicSureExcess($this->getPicSureExcess());

        return $premium;
    }
}
