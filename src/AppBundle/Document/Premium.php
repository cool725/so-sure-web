<?php

namespace AppBundle\Document;

use AppBundle\Classes\Salva;
use AppBundle\Document\Excess\Excess;
use AppBundle\Interfaces\EqualsInterface;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\EmbeddedDocument
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"phone"="AppBundle\Document\PhonePremium"})
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
abstract class Premium implements EqualsInterface
{
    use CurrencyTrait;

    const SOURCE_OFFER = "offer";
    const SOURCE_RENEWAL = "renewal";
    const SOURCE_PHONE = "phone";

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $gwp;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $ipt;

    /**
     * @Assert\Range(min=0,max=1)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $iptRate;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $annualDiscount;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Excess\Excess")
     * @Gedmo\Versioned
     * @var Excess|null
     */
    protected $excess;

    /**
     * Tells us the stream of pricing the originating price belonged to.
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $stream;

    /**
     * Tells us by what path did the premium get onto the policy.
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $source;

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

    public function getAnnualDiscount()
    {
        return $this->annualDiscount;
    }

    public function setAnnualDiscount($annualDiscount)
    {
        $this->annualDiscount = $annualDiscount;
    }

    public function hasAnnualDiscount()
    {
        return $this->getAnnualDiscount() > 0 && !$this->areEqualToTwoDp(0, $this->getAnnualDiscount());
    }

    /**
     * @param float $discount Only use pre-renewal to indicate possible monthly premium values
     */
    public function getMonthlyDiscount($discount = null)
    {
        if (!$discount) {
            $discount = $this->annualDiscount;
        }
        return floor(100 * $discount / 12) / 100;
    }

    /**
     * @param float $discount Only use pre-renewal to indicate possible monthly premium values
     */
    public function getAdjustedFinalMonthlyPremiumPrice($discount = null)
    {
        if (!$discount) {
            $discount = $this->annualDiscount;
        }
        $monthlyAdjustment = $discount - ($this->getMonthlyDiscount($discount) * 11);

        return $this->toTwoDp($this->getMonthlyPremiumPrice() - $monthlyAdjustment);
    }

    /**
     * @param float $discount Only use pre-renewal to indicate possible monthly premium values
     */
    public function getAdjustedStandardMonthlyPremiumPrice($discount = null)
    {
        return $this->toTwoDp($this->getMonthlyPremiumPrice() - $this->getMonthlyDiscount($discount));
    }

    /**
     * @param float $discount Only use pre-renewal to indicate possible monthly premium values
     */
    public function getAdjustedYearlyPremiumPrice($discount = null)
    {
        if (!$discount) {
            $discount = $this->annualDiscount;
        }

        return $this->toTwoDp($this->getYearlyPremiumPrice() - $discount);
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

    public function getExcess()
    {
        return $this->excess;
    }

    public function setExcess(Excess $excess)
    {
        $this->excess = $excess;
    }

    public function clearExcess()
    {
        $this->excess = null;
    }

    /**
     * Gets this premium's originating price's stream.
     * @return string|null the stream if existent.
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Sets the premium's originating stream.
     * @param string $stream is the stream to set it to.
     */
    public function setStream($stream)
    {
        if (!in_array(PhonePrice::STREAM_POSITIONS)) {
            throw new \IllegalArgumentException(sprintf(
                "'%s' is not a stream position for premium '%s'",
                $stream,
                $this->getId()
            );
        }
        $this->stream = $stream;
    }

    /**
     * Gives you the source of this premium.
     * @return string the source.
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * Sets the source of the premium.
     * @param string $source is the source of the premium.
     */
    public function setSource($source)
    {
        $this->source = $source;
    }

    public function isEvenlyDivisible($amount, $accountInitial = false)
    {
        if (!$accountInitial) {
            $divisible = $amount / $this->getMonthlyPremiumPrice();
        } elseif ($this->areEqualToTwoDp($this->getAdjustedFinalMonthlyPremiumPrice(), $amount)) {
            $divisible = $amount / $this->getAdjustedFinalMonthlyPremiumPrice();
        } elseif ($amount > $this->getAdjustedStandardMonthlyPremiumPrice()) {
            // most likely if amount is more than one payment, it would be a full payoff
            $divisible = ($amount - $this->getAdjustedFinalMonthlyPremiumPrice()) /
                $this->getAdjustedStandardMonthlyPremiumPrice();

            // but just in case its 2 months (for example), allow that as well
            if (!$this->isWholeInteger($divisible)) {
                $divisible = $amount / $this->getAdjustedStandardMonthlyPremiumPrice();
            }
        } else {
            $divisible = $amount / $this->getAdjustedStandardMonthlyPremiumPrice();
        }

        return $this->isWholeInteger($divisible);
    }

    public function getNumberOfMonthlyPayments($amount)
    {
        if ($this->isEvenlyDivisible($amount)) {
            $divisible = $amount / $this->getAdjustedStandardMonthlyPremiumPrice();
            $numPayments = round($divisible, 0);

            return $numPayments;
        } elseif ($this->isEvenlyDivisible($amount, true)) {
            if ($this->areEqualToTwoDp($this->getAdjustedFinalMonthlyPremiumPrice(), $amount)) {
                $numPayments = 1;
            } elseif ($amount > $this->getAdjustedStandardMonthlyPremiumPrice()) {
                $divisible = ($amount - $this->getAdjustedFinalMonthlyPremiumPrice()) /
                    $this->getAdjustedStandardMonthlyPremiumPrice();
                $numPayments = round($divisible, 0) + 1;
            } else {
                $divisible = $amount / $this->getAdjustedStandardMonthlyPremiumPrice();
                $numPayments = round($divisible, 0);
            }

            return $numPayments;
        } else {
            return null;
        }
    }

    public function getNumberOfScheduledMonthlyPayments($amount)
    {
        if ($monthlyPayments = $this->getNumberOfMonthlyPayments($amount)) {
            return 13 - $monthlyPayments;
        }

        return null;
    }

    public function getYearlyTotalCommission()
    {
        return Salva::YEARLY_TOTAL_COMMISSION;
    }

    public function equals($compare)
    {
        if (!$compare || !$compare instanceof Premium) {
            return false;
        }

        if (!$this->areEqualToTwoDp($this->getGwp(), $compare->getGwp())) {
            return false;
        } elseif (!$this->areEqualToTwoDp($this->getIpt(), $compare->getIpt())) {
            return false;
        } elseif (!$this->areEqualToTwoDp($this->getIptRate(), $compare->getIptRate())) {
            return false;
        }

        return true;
    }
}
