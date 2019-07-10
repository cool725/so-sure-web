<?php

namespace AppBundle\Document;

use AppBundle\Exception\PolicyPhonePriceException;

class PolicyPhonePrice
{
    /**
     * @var PhonePolicy
     */
    private $policy;

    /**
     * @var Phone
     */
    private $phone;

    /**
     * @var Price
     */
    private $currentPhonePrice;

    /**
     * @var float
     */
    private $monthlyPremiumPrice;

    const FAILED_TO_GET_PHONE = 501;
    const FAILED_TO_GET_PRICE = 502;
    const FAILED_TO_GET_MONTHLY_PREMIUM = 503;

    public function __construct(PhonePolicy $policy)
    {
        $this->policy = $policy;
        try {
            $this->setPhone($policy->getPhone());
        } catch (\Exception $e) {
            throw new PolicyPhonePriceException(
                sprintf(
                    "Could not get phone for policy",
                    $policy->getId()
                ),
                self::FAILED_TO_GET_PHONE
            );
        }
        try {
            $this->setCurrentPhonePrice($this->phone->getCurrentPhonePrice());
        } catch (\Exception $e) {
            throw new PolicyPhonePriceException(
                sprintf(
                    "Could not get current phone price for phone %s on policy %s",
                    $this->getPhone()->getId(),
                    $policy->getId()
                ),
                self::FAILED_TO_GET_PRICE
            );
        }
        try {
            $this->setMonthlyPremiumPrice($this->getCurrentPhonePrice()->getMonthlyPremiumPrice());
        } catch (\Exception $e) {
            throw new PolicyPhonePriceException(
                sprintf(
                    "Could not set monthly premium price based on phone %s on policy %s",
                    $this->getPhone()->getId(),
                    $policy->getId()
                ),
                self::FAILED_TO_GET_MONTHLY_PREMIUM
            );
        }
    }

    /**
     * @return PhonePolicy
     */
    public function getPolicy(): PhonePolicy
    {
        return $this->policy;
    }

    /**
     * @param PhonePolicy $policy
     * @return PolicyPhonePrice
     */
    public function setPolicy(PhonePolicy $policy): PolicyPhonePrice
    {
        $this->policy = $policy;
        return $this;
    }

    /**
     * @return Phone
     */
    public function getPhone(): Phone
    {
        return $this->phone;
    }

    /**
     * @param Phone $phone
     * @return PolicyPhonePrice
     */
    public function setPhone(Phone $phone): PolicyPhonePrice
    {
        $this->phone = $phone;
        return $this;
    }

    /**
     * @return Price
     */
    public function getCurrentPhonePrice(): Price
    {
        return $this->currentPhonePrice;
    }

    /**
     * @param Price $currentPhonePrice
     * @return PolicyPhonePrice
     */
    public function setCurrentPhonePrice(Price $currentPhonePrice): PolicyPhonePrice
    {
        $this->currentPhonePrice = $currentPhonePrice;
        return $this;
    }

    /**
     * @return float
     */
    public function getMonthlyPremiumPrice(): float
    {
        return $this->monthlyPremiumPrice;
    }

    /**
     * @param float $monthlyPremiumPrice
     * @return PolicyPhonePrice
     */
    public function setMonthlyPremiumPrice(float $monthlyPremiumPrice): PolicyPhonePrice
    {
        $this->monthlyPremiumPrice = $monthlyPremiumPrice;
        return $this;
    }

}
