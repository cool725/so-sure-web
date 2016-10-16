<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\EmbeddedDocument
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"phone"="AppBundle\Document\PhonePrice"})
 */
abstract class Price
{
    use CurrencyTrait;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     */
    protected $validFrom;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     */
    protected $validTo;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     */
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
        return $this->toTwoDp($this->getGwp() * $this->getCurrentIptRate($date));
    }

    public function getMonthlyPremiumPrice(\DateTime $date = null)
    {
        return $this->getGwp() + $this->getIpt($date);
    }

    public function getYearlyPremiumPrice(\DateTime $date = null)
    {
        return $this->toTwoDp($this->getMonthlyPremiumPrice($date) * 12);
    }

    public function getYearlyGwp()
    {
        return $this->toTwoDp($this->getGwp() * 12);
    }

    public function setMonthlyPremiumPrice($premium, \DateTime $date = null)
    {
        $this->setGwp($this->toTwoDp($premium / (1 + $this->getCurrentIptRate($date))));
    }

    abstract public function createPremium();

    protected function populatePremium(Premium $premium, \DateTime $date = null)
    {
        $premium->setGwp($this->getGwp());
        $premium->setIpt($this->getIpt($date));
        $premium->setIptRate($this->getCurrentIptRate($date));
    }

    public function toApiArray(\DateTime $date = null)
    {
        return [
            'valid_from' => $this->getValidFrom()->format(\DateTime::ATOM),
            'valid_to' => $this->getValidTo() ? $this->getValidTo()->format(\DateTime::ATOM) : null,
            'gwp' => $this->getGwp(),
            'premium' => $this->getMonthlyPremiumPrice($date),
        ];
    }
}
