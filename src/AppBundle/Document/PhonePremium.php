<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\EmbeddedDocument
 */
class PhonePremium extends Premium
{
    use CurrencyTrait;

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
}
