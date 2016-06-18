<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PhonePolicyRepository")
 * @Gedmo\Loggable
 */
class PhonePolicy extends Policy
{
    const STANDARD_VALUE = 10;
    const AGED_VALUE = 2;
    const NETWORK_CLAIM_VALUE = 2;
    const PROMO_LAUNCH_VALUE = 5;

    use ArrayToApiArrayTrait;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @Gedmo\Versioned
     */
    protected $phone;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $phoneData;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $imei;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $serialNumber;

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $checkmendCerts = array();

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone(Phone $phone)
    {
        $this->phone = $phone;
        if (!$phone->getCurrentPhonePrice()) {
            throw new \Exception('Phone must have a price');
        }

        $this->setPremium($phone->getCurrentPhonePrice()->createPremium());
    }

    public function getImei()
    {
        return $this->imei;
    }

    public function setImei($imei)
    {
        $this->imei = $imei;
    }

    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = $serialNumber;
    }

    public function getPhoneData()
    {
        return $this->phoneData;
    }

    public function setPhoneData($phoneData)
    {
        $this->phoneData = $phoneData;
    }

    public function getCheckmendCerts()
    {
        return $this->checkmendCerts;
    }

    public function addCheckmendCerts($key, $value)
    {
        $this->checkmendCerts[$key] = $value;
    }

    public function getTotalConnectionValue(\DateTime $date = null)
    {
        return $this->getConnectionValue($date) + $this->getPromoConnectionValue($date);
    }

    public function getConnectionValue(\DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return 0;
        }
        if (!$this->getUser()) {
            throw new \Exception('Policy is missing a user');
        }

        if ($this->hasMonetaryClaimed()) {
            // should never occur, but just in case
            return 0;
        } elseif ($this->hasMonetaryNetworkClaim()) {
            return self::NETWORK_CLAIM_VALUE;
        } elseif ($this->isPolicyWithin60Days($date)) {
            return self::STANDARD_VALUE;
        } elseif ($this->isBeforePolicyStarted($date)) {
            // Case for Salva's 10 minute buffer
            return self::STANDARD_VALUE;
        } else {
            return self::AGED_VALUE;
        }
    }

    public function getPromoConnectionValue(\DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return 0;
        }
        if (!$this->getUser()) {
            throw new \Exception('Policy is missing a user');
        }

        // any claims should have a 0 value
        if ($this->hasMonetaryClaimed() || $this->hasMonetaryNetworkClaim()) {
            return 0;
        }

        // Extra Case for Salva's 10 minute buffer
        if ($this->isPolicyWithin60Days($date) || $this->isBeforePolicyStarted($date)) {
            if ($this->getUser()->isPreLaunch() || $this->getPromoCode() == self::PROMO_LAUNCH) {
                return self::PROMO_LAUNCH_VALUE;
            }
        }

        return 0;
    }

    public function getAllowedConnectionValue(\DateTime $date = null)
    {
        return $this->getAllowedStandardOrPromoConnectionValue(false, $date);
    }

    public function getAllowedPromoConnectionValue(\DateTime $date = null)
    {
        return $this->getAllowedStandardOrPromoConnectionValue(true, $date);
    }

    private function getMaxPotRemainder()
    {
        $potValue = $this->getPotValue();
        $maxPot = $this->getMaxPot();

        return $maxPot - $potValue;
    }

    private function getAllowedStandardOrPromoConnectionValue($promoCodeOnly, \DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return 0;
        }

        $connectionValue = $this->getConnectionValue($date);
        if ($promoCodeOnly) {
            $maxPromoPotRemainder = $this->getMaxPotRemainder() - $connectionValue;

            // If its the last connection, check that the initial bit of the connection value hasn't alredy been used up
            if ($maxPromoPotRemainder <= 0) {
                return 0;
            }

            // Get the promo connection value
            $connectionValue = $this->getPromoConnectionValue($date);

            // If its the last connection, then may be less than the full £15/£10/£2
            if ($connectionValue > $maxPromoPotRemainder) {
                $connectionValue = $maxPromoPotRemainder;
            }
        } else {
            // If its the last connection, then may be less than the full £15/£10/£2
            if ($this->getMaxPotRemainder() < $connectionValue) {
                $connectionValue = $this->getMaxPotRemainder();
            }
        }

        // Should never be the case, but ensure connectionValue isn't negative
        if ($connectionValue < 0) {
            $connectionValue = 0;
        }

        return $connectionValue;
    }

    public function getMaxConnections(\DateTime $date = null)
    {
        if (!$this->isPolicy() || $this->getConnectionValue($date) == 0) {
            return 0;
        }
        if (!$this->getUser()) {
            throw new \Exception('Policy is missing a user');
        }

        return (int) ceil($this->getMaxPot() / $this->getTotalConnectionValue($date));
    }

    public function getMaxPot()
    {
        if (!$this->isPolicy()) {
            return 0;
        }
        if (!$this->getUser()) {
            throw new \Exception('Policy is missing a user');
        }

        if ($this->getUser()->isPreLaunch() || $this->getPromoCode() == self::PROMO_LAUNCH) {
            // 100% of policy
            return $this->getPremium()->getYearlyPremiumPrice();
        } else {
            return $this->toTwoDp($this->getPremium()->getYearlyPremiumPrice() * 0.8);
        }
    }

    public function getPolicyNumberPrefix()
    {
        return 'Mob';
    }

    public function toApiArray()
    {
        return array_merge(parent::toApiArray(), [
            'phone_policy' => [
                'imei' => $this->getImei(),
                'phone' => $this->getPhone() ? $this->getPhone()->toApiArray() : null,
            ]
        ]);
    }
}
