<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\PhonePolicy;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\PhoneTrait;

class Renew
{
    use CurrencyTrait;
    use PhoneTrait;

    /** @var PhonePolicy */
    protected $policy;

    /**
     * @Assert\Range(
     *      min = 1,
     *      max = 200,
     *      minMessage = "You must select monthly or annual policy payments",
     *      maxMessage = "You must select monthly or annual policy payments"
     * )
     * @Assert\NotNull(message="You must select monthly or annual policy payments")
     */
    protected $amount;

    /**
     * @var boolean
     * @Assert\IsTrue(message="You must agree to our terms")
     */
    protected $agreed;

    /**
     * @var boolean
     */
    protected $custom;

    protected $encodedAmount;

    protected $numPayments;

    protected $usePot;
    
    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(PhonePolicy $policy)
    {
        $this->policy = $policy;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function getEncodedAmount()
    {
        return $this->encodedAmount;
    }

    public function setEncodedAmount($encodedAmount)
    {
        $data = explode("|", $encodedAmount);
        $amount = $data[0];
        $numPayments = $data[1];
        $usePot = $data[2];

        // TODO: allow discounted price as well
        $price = $this->getPolicy()->getPhone()->getCurrentPhonePrice();
        $monthlyPrice = $price->getMonthlyPremiumPrice();
        $potValue = $this->getPolicy()->getPotValue();
        $monthlyInitialAdjustedPrice = $price->getAdjustedInitialMonthlyPremiumPrice($potValue);
        $monthlyStandardAdjustedPrice = $price->getAdjustedStandardMonthlyPremiumPrice($potValue);
        $yearlyPrice = $price->getYearlyPremiumPrice();
        $yearlyAdjustedPrice = $price->getAdjustedYearlyPremiumPrice($potValue);

        if (!$this->isCustom() &&
            !$this->areEqualToTwoDp($amount, $monthlyInitialAdjustedPrice) &&
            !$this->areEqualToTwoDp($amount, $monthlyStandardAdjustedPrice) &&
            !$this->areEqualToTwoDp($amount, $yearlyAdjustedPrice)) {
            throw new \InvalidArgumentException(sprintf('Amount must be a monthly or annual figure'));
        } elseif ($this->isCustom() &&
            !$this->areEqualToTwoDp($amount, $monthlyPrice) &&
            !$this->areEqualToTwoDp($amount, $yearlyPrice)) {
            throw new \InvalidArgumentException(sprintf('Amount must be a monthly or annual figure'));
        }

        $this->encodedAmount = $encodedAmount;
        $this->amount = $amount;
        $this->numPayments = $numPayments;
        $this->usePot = $usePot;
    }

    public function setAgreed($agreed)
    {
        $this->agreed = $agreed;
    }

    public function isAgreed()
    {
        return $this->agreed;
    }

    public function setCustom($custom)
    {
        $this->custom = $custom;
    }

    public function isCustom()
    {
        return $this->custom;
    }

    public function getNumPayments()
    {
        return $this->numPayments;
    }

    public function getUsePot()
    {
        return $this->usePot;
    }
}
