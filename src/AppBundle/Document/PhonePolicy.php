<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PhonePolicyRepository")
 */
class PhonePolicy extends Policy
{
    use ArrayToApiArrayTrait;

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /** @MongoDB\ReferenceOne(targetDocument="Phone") */
    protected $phone;

    /** @MongoDB\Field(type="string", name="phone_data") */
    protected $phoneData;

    /** @MongoDB\Field(type="string", nullable=false) */
    protected $imei;

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone(Phone $phone)
    {
        $this->phone = $phone;
        if (!$phone->getCurrentPolicyPremium()) {
            throw new \Exception('Phone must have a premium');
        }

        $this->setPremium($phone->getCurrentPolicyPremium());
    }

    public function getImei()
    {
        return $this->imei;
    }

    public function setImei($imei)
    {
        $this->imei = $imei;
    }

    public function getPhoneData()
    {
        return $this->phoneData;
    }

    public function setPhoneData($phoneData)
    {
        $this->phoneData = $phoneData;
    }

    public function getConnectionValue(\DateTime $date = null)
    {
        if (!$this->isPolicy() || $this->isPolicyWithin60Days($date)) {
            return 10;
        } else {
            return 2;
        }
    }

    public function getMaxConnections()
    {
        return $this->getPremium()->getMaxConnections();
    }

    public function getMaxPot()
    {
        return $this->getPremium()->getMaxPot();
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
