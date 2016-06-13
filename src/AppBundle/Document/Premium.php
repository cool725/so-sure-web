<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\EmbeddedDocument
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"phone"="AppBundle\Document\PhonePremium"})
 */
abstract class Premium
{
    /** @MongoDB\Field(type="float") */
    protected $gwp;

    /** @MongoDB\Field(type="float") */
    protected $ipt;

    /** @MongoDB\Field(type="float") */
    protected $iptRate;

    public function __construct()
    {
    }

    public function getGwp()
    {
        return $this->toTwoDp($this->gwp);
    }

    public function setGwp($gwp)
    {
        $this->gwp = $gwp;
    }

    public function getIpt()
    {
        return $this->toTwoDp($this->ipt);
    }

    public function setIpt($ipt)
    {
        $this->ipt = $ipt;
    }

    public function getIptRate()
    {
        return $this->iptRate;
    }

    public function setIptRate($iptRate)
    {
        $this->iptRate = $iptRate;
    }

    public function getMonthlyPremiumPrice()
    {
        return $this->getGwp() + $this->getIpt();
    }

    public function getYearlyPremiumPrice()
    {
        return $this->toTwoDp($this->getMonthlyPremiumPrice() * 12);
    }

    public function getYearlyGwp()
    {
        // Calculate based on yearly figure
        return $this->toTwoDp($this->getYearlyGwpActual());
    }

    public function getTotalIpt()
    {
        // Calculate based on yearly figure
        return $this->toTwoDp($this->getYearlyPremiumPrice() - $this->getYearlyGwpActual());
    }

    protected function getYearlyGwpActual()
    {
        return $this->getYearlyPremiumPrice() / (1 + $this->getIptRate());
    }
}
