<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\PhonePolicy;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\Policy;
use AppBundle\Document\Cashback;

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

    public function getMonthlyPremiumPrice()
    {
        // TODO: Current Price or new policy price? Depends on IPT
        $price = $this->getPolicy()->getPhone()->getCurrentPhonePrice();
        return $price->getMonthlyPremiumPrice();
    }

    public function getYearlyPremiumPrice()
    {
        // TODO: Current Price or new policy price? Depends on IPT
        $price = $this->getPolicy()->getPhone()->getCurrentPhonePrice();
        return $price->getYearlyPremiumPrice();
    }

    public function getAdjustedStandardMonthlyPremiumPrice()
    {
        $potValue = $this->getPolicy()->getPotValue();
        // TODO: Current Price or new policy price? Depends on IPT
        $price = $this->getPolicy()->getPhone()->getCurrentPhonePrice();

        return $price->getAdjustedStandardMonthlyPremiumPrice($potValue);
    }

    public function getAdjustedYearlyPremiumPrice()
    {
        $potValue = $this->getPolicy()->getPotValue();
        // TODO: Current Price or new policy price? Depends on IPT
        $price = $this->getPolicy()->getPhone()->getCurrentPhonePrice();

        return $price->getAdjustedYearlyPremiumPrice($potValue);
    }

    public function useSimpleAmount()
    {
        if ($this->getPolicy()->getPremiumPlan() == Policy::PLAN_MONTHLY) {
            $this->setEncodedAmount(implode("|", [$this->getAdjustedStandardMonthlyPremiumPrice(), 12, 1]));
        } elseif ($this->getPolicy()->getPremiumPlan() == Policy::PLAN_YEARLY) {
            $this->setEncodedAmount(implode("|", [$this->getAdjustedYearlyPremiumPrice(), 1, 1]));
        }
    }

    public function setEncodedAmount($encodedAmount)
    {
        $data = explode("|", $encodedAmount);
        $amount = $data[0];
        $numPayments = $data[1];
        $usePot = filter_var($data[2], FILTER_VALIDATE_BOOLEAN);

        $potValue = $this->getPolicy()->getPotValue();
        // TODO: allow discounted price as well
        // TODO: Current Price or new policy price? Depends on IPT
        $price = $this->getPolicy()->getPhone()->getCurrentPhonePrice();
        $monthlyPrice = $price->getMonthlyPremiumPrice();
        $monthlyFinalAdjustedPrice = $price->getAdjustedFinalMonthlyPremiumPrice($potValue);
        $monthlyStandardAdjustedPrice = $price->getAdjustedStandardMonthlyPremiumPrice($potValue);
        $yearlyPrice = $price->getYearlyPremiumPrice();
        $yearlyAdjustedPrice = $price->getAdjustedYearlyPremiumPrice($potValue);

        if ($usePot &&
            !$this->areEqualToTwoDp($amount, $monthlyFinalAdjustedPrice) &&
            !$this->areEqualToTwoDp($amount, $monthlyStandardAdjustedPrice) &&
            !$this->areEqualToTwoDp($amount, $yearlyAdjustedPrice)) {
            throw new \InvalidArgumentException(sprintf(
                'Amount must be a monthly or annual figure. Not %f. Expected: [%f, %f, %f]',
                $amount,
                $monthlyFinalAdjustedPrice,
                $monthlyStandardAdjustedPrice,
                $yearlyAdjustedPrice
            ));
        } elseif (!$usePot &&
            !$this->areEqualToTwoDp($amount, $monthlyPrice) &&
            !$this->areEqualToTwoDp($amount, $yearlyPrice)) {
            throw new \InvalidArgumentException(sprintf(
                'Amount must be a monthly or annual figure. Not %f. Expected: [%f, %f]',
                $amount,
                $monthlyPrice,
                $yearlyPrice
            ));
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
