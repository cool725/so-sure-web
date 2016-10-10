<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\EmbeddedDocument
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"phone"="AppBundle\Document\PhonePremium"})
 */
abstract class Premium
{
    use CurrencyTrait;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     */
    protected $gwp;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     */
    protected $ipt;

    /**
     * @Assert\Range(min=0,max=1)
     * @MongoDB\Field(type="float")
     */
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

    public function getYearlyIpt()
    {
        // Calculate based on yearly figure
        return $this->toTwoDp($this->getYearlyIptActual());
    }

    public function getYearlyGwpActual()
    {
        return $this->getYearlyPremiumPrice() / (1 + $this->getIptRate());
    }

    public function getYearlyIptActual()
    {
        return $this->getYearlyGwpActual() * $this->getIptRate();
    }
}
