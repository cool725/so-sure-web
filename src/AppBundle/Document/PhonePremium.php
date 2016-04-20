<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\EmbeddedDocument
 */
class PhonePremium extends Premium
{
    use CurrencyTrait;

    /** @MongoDB\Field(type="float") */
    protected $policyPrice;

    /** @MongoDB\Field(type="float") */
    protected $lossPrice;

    public function __construct()
    {
    }

    public function getPolicyPrice()
    {
        return $this->toTwoDp($this->policyPrice);
    }

    public function setPolicyPrice($policyPrice)
    {
        $this->policyPrice = $policyPrice;
    }

    public function getYearlyPolicyPrice()
    {
        return $this->toTwoDp($this->getPolicyPrice() * 12);
    }

    public function getLossPrice()
    {
        return $this->toTwoDp($this->lossPrice);
    }

    public function setLossPrice($lossPrice)
    {
        $this->lossPrice = $lossPrice;
    }

    public function getYearlyLossPrice()
    {
        return $this->toTwoDp($this->lossPrice * 12);
    }

    public function getTotalPrice()
    {
        return $this->getPolicyPrice() + $this->getLossPrice();
    }

    public function getYearlyTotalPrice()
    {
        return $this->toTwoDp($this->getTotalPrice() * 12);
    }

    public function getMaxPot()
    {
        return $this->toTwoDp($this->getYearlyTotalPrice() * 0.8);
    }

    public function getMaxConnections()
    {
        return (int) ceil($this->getMaxPot() / $this->getConnectionValue());
    }

    public function getConnectionValue()
    {
        return 10;
    }
}
