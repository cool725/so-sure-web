<?php

namespace AppBundle\Helpers;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePrice;
use AppBundle\Exception\PolicyPhonePriceException;

class PolicyPhonePriceHelper
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
     * @var PhonePrice|null
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
                    "Could not get phone for policy %s",
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
            if (!$this->getCurrentPhonePrice()) {
                throw new PolicyPhonePriceException(
                    sprintf(
                        "Could not get current phone price for phone %s on policy %s",
                        $this->getPhone()->getId(),
                        $policy->getId()
                    ),
                    self::FAILED_TO_GET_PRICE
                );
            }
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
    public function getPolicy()
    {
        return $this->policy;
    }

    /**
     * @param PhonePolicy $policy
     * @return PolicyPhonePriceHelper
     */
    public function setPolicy(PhonePolicy $policy)
    {
        $this->policy = $policy;
        return $this;
    }

    /**
     * @return Phone
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * @param Phone $phone
     * @return PolicyPhonePriceHelper
     */
    public function setPhone(Phone $phone)
    {
        $this->phone = $phone;
        return $this;
    }

    /**
     * @return PhonePrice|null
     */
    public function getCurrentPhonePrice()
    {
        return $this->currentPhonePrice;
    }

    /**
     * @param PhonePrice|null $currentPhonePrice
     * @return PolicyPhonePriceHelper
     */
    public function setCurrentPhonePrice(PhonePrice $currentPhonePrice = null)
    {
        $this->currentPhonePrice = $currentPhonePrice;
        return $this;
    }

    /**
     * @return float
     */
    public function getMonthlyPremiumPrice()
    {
        return $this->monthlyPremiumPrice;
    }

    /**
     * @param float $monthlyPremiumPrice
     * @return PolicyPhonePriceHelper
     */
    public function setMonthlyPremiumPrice(float $monthlyPremiumPrice)
    {
        $this->monthlyPremiumPrice = $monthlyPremiumPrice;
        return $this;
    }
}
