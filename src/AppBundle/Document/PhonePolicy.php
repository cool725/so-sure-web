<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class PhonePolicy extends Policy
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     */
    protected $phone;

    /** @MongoDB\Field(type="string", nullable=false) */
    protected $imei;

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone(Phone $phone)
    {
        $this->phone = $phone;
    }

    public function getImei()
    {
        return $this->imei;
    }

    public function setImei($imei)
    {
        $this->imei = $imei;
    }

    public function toApiArray()
    {
        return [
          'id' => $this->getId(),
          'status' => $this->getStatus(),
          'policy_number' => $this->getPolicyNumber(),
          'imei' => $this->getImei(),
          'phone' => $this->getPhone() ? $this->getUser()->toApiArray() : null,
          'user' => $this->getUser() ? $this->getUser()->toApiArray() : null,
          'pot' => null,
          'payment' => null,
        ];
    }
}
