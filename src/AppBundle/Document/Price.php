<?php

namespace AppBundle\Document;

use AppBundle\Document\Excess\Excess;
use AppBundle\Document\Excess\PhoneExcess;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\EmbeddedDocument
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"phone"="AppBundle\Document\PhonePrice"})
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
abstract class Price
{
    use CurrencyTrait;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $validFrom;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $validTo;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     */
    protected $gwp;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="1500")
     * @MongoDB\Field(type="string")
     */
    protected $notes;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Excess\Excess")
     * @Gedmo\Versioned
     * @var Excess|null
     */
    protected $excess;

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
        return $this->calculateIpt($this->getGwp(), $date);
    }

    public function calculateIpt($gwp, \DateTime $date = null)
    {
        return $this->toTwoDp($gwp * $this->getCurrentIptRate($date));
    }

    public function getMonthlyPremiumPrice($additionalPremium = null, \DateTime $date = null)
    {
        $gwp = $this->getGwp();
        if ($additionalPremium) {
            $gwp = $gwp + $additionalPremium;
        }

        return $this->toTwoDp($gwp + $this->calculateIpt($gwp, $date));
    }

    public function getYearlyPremiumPrice($additionalPremium = null, \DateTime $date = null)
    {
        return $this->toTwoDp($this->getMonthlyPremiumPrice($additionalPremium, $date) * 12);
    }

    public function getAdjustedFinalMonthlyPremiumPrice($potValue, \DateTime $date = null)
    {
        $monthlyAdjustment = floor(100 * $potValue / 12) / 100;
        $monthlyAdjustment = $potValue - ($monthlyAdjustment * 11);

        return $this->toTwoDp($this->getMonthlyPremiumPrice(null, $date) - $monthlyAdjustment);
    }

    public function getAdjustedStandardMonthlyPremiumPrice($potValue, \DateTime $date = null)
    {
        $monthlyAdjustment = floor(100 * $potValue / 12) / 100;
        return $this->toTwoDp($this->getMonthlyPremiumPrice(null, $date) - $monthlyAdjustment);
    }

    public function getAdjustedYearlyPremiumPrice($potValue, \DateTime $date = null)
    {
        return $this->toTwoDp($this->getYearlyPremiumPrice(null, $date) - $potValue);
    }

    public function getYearlyGwp()
    {
        return $this->toTwoDp($this->getGwp() * 12);
    }

    public function setMonthlyPremiumPrice($premium, \DateTime $date = null)
    {
        $this->setGwp($this->toTwoDp($premium / (1 + $this->getCurrentIptRate($date))));
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function setNotes($notes)
    {
        $this->notes = $notes;
    }

    /**
     * @return Excess|null
     */
    public function getExcess()
    {
        return $this->excess;
    }

    /**
     * @return PhoneExcess|null
     */
    public function getPhoneExcess()
    {
        if ($this->excess instanceof PhoneExcess) {
            return $this->excess;
        }

        return null;
    }

    public function setExcess(Excess $excess)
    {
        $this->excess = $excess;
    }

    abstract public function createPremium($additionalGwp = null, \DateTime $date = null);

    protected function populatePremium(Premium $premium, $additionalGwp = null, \DateTime $date = null)
    {
        $gwp = $this->getGwp();
        if ($additionalGwp) {
            $gwp = $gwp + $additionalGwp;
        }
        $premium->setGwp($this->toTwoDp($gwp));
        $premium->setIpt($this->calculateIpt($gwp, $date));
        $premium->setIptRate($this->getCurrentIptRate($date));
        if ($this->getExcess()) {
            $premium->setExcess($this->getExcess());
        }
    }

    public function toApiArray(\DateTime $date = null)
    {
        return [
            'valid_from' => $this->getValidFrom()->format(\DateTime::ATOM),
            'valid_to' => $this->getValidTo() ? $this->getValidTo()->format(\DateTime::ATOM) : null,
            'gwp' => $this->getGwp(),
            'premium' => $this->getMonthlyPremiumPrice(null, $date),
            'notes' => $this->getNotes(),
        ];
    }

    public function toPriceArray(\DateTime $date = null)
    {
        return array_merge($this->toApiArray($date), [
            'initial_premium' => $this->getMonthlyPremiumPrice(null, $this->getValidFrom()),
            'final_premium' => $this->getValidTo() ? $this->getMonthlyPremiumPrice(null, $this->getValidTo()) : null,
            'excess' => $this->getExcess() ? $this->getExcess()->toPriceArray() : null,
            'excess_detail' => $this->getExcess() ? $this->getExcess()->toPriceArray()['detail'] : '??',
        ]);
    }
}
