<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\EmbeddedDocument
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"phone"="AppBundle\Document\PhonePrice"})
 */
abstract class Price
{
    /** @MongoDB\Date() */
    protected $validFrom;

    /** @MongoDB\Date() */
    protected $validTo;

    /** @MongoDB\Field(type="float") */
    protected $gwp;

    public function __construct()
    {
    }

    public function getValidFrom()
    {
        return $this->validFrom;
    }

    public function setValidFrom($validFrom)
    {
        $this->validFrom = $validFrom;
    }

    public function getValidTo()
    {
        return $this->validTo;
    }

    public function setValidTo($validTo)
    {
        $this->validTo = $validTo;
    }

    public function getGwp()
    {
        return $this->toTwoDp($this->gwp);
    }

    public function setGwp($gwp)
    {
        $this->gwp = $gwp;
    }

    public function getIpt(\DateTime $date = null)
    {
        return $this->toTwoDp($this->getGwp() * $this->getIptRate($date));
    }

    public function getMonthlyPremiumPrice()
    {
        return $this->getGwp() + $this->getIpt();
    }

    public function getYearlyPremiumPrice()
    {
        return $this->toTwoDp($this->getMonthlyPremiumPrice() * 12);
    }

    public function setMonthlyPremiumPrice($premium, \DateTime $date = null)
    {
        $this->setGwp($premium / (1 + $this->getIptRate($date)));
    }

    abstract function createPremium();

    protected function populatePremium(Premium $premium, \DateTime $date = null)
    {
        $premium->setGwp($this->getGwp());
        $premium->setIpt($this->getIpt($date));
    }
}
