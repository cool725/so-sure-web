<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\EmbeddedDocument
 */
class PhonePrice extends Price
{
    use CurrencyTrait;
    const BROKER_FEE = 0.121;

    public function __construct()
    {
    }

    public function getMaxPot()
    {
        return $this->toTwoDp($this->getYearlyPremiumPrice() * 0.8);
    }

    public function getMaxConnections()
    {
        return (int) ceil($this->getMaxPot() / $this->getInitialConnectionValue());
    }

    public function getInitialConnectionValue()
    {
        return 10;
    }

    public function createPremium(\DateTime $date = null)
    {
        $premium = new PhonePremium();
        $this->populatePremium($premium, $date);

        return $premium;
    }
}
