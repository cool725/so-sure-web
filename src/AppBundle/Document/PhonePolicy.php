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
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $checkmendCert;

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

    public function getCheckmendCert()
    {
        return $this->checkmendCert;
    }

    public function setCheckmendCert($checkmendCert)
    {
        $this->checkmendCert = $checkmendCert;
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
            return 2;
        } elseif ($this->isPolicyWithin60Days($date)) {
            return 10;
        } else {
            return 2;
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

        if ($this->isPolicyWithin60Days($date)) {
            if ($this->getUser()->isPreLaunch() || $this->getPromoCode() == self::PROMO_LAUNCH) {
                return 5;
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

    private function getAllowedStandardOrPromoConnectionValue($promoCodeOnly, \DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return 0;
        }

        if ($promoCodeOnly) {
            $connectionValue = $this->getPromoConnectionValue($date);
        } else {
            $connectionValue = $this->getConnectionValue($date);
        }

        // If its the last connection, then may be less than the full £15/£10/£2
        $potValue = $this->getPotValue();
        $maxPot = $this->getMaxPot();
        if ($potValue + $connectionValue > $maxPot) {
            $connectionValue = $maxPot - $potValue;
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

        return (int) ceil($this->getMaxPot() / $this->getConnectionValue($date));
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
