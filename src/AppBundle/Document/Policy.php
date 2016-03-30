<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class Policy
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_UNPAID = 'unpaid';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     */
    protected $phone;

    /**
     * @MongoDB\ReferenceMany(targetDocument="Payment", mappedBy="policy")
     */
    protected $payments;
    
    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     */
    protected $user;

    /** @MongoDB\Field(type="string") */
    protected $status;

    /** @MongoDB\Field(type="string", nullable=true) */
    protected $policyNumber;

    /** @MongoDB\Field(type="string", nullable=false) */
    protected $imei;

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\Date(nullable=true) */
    protected $start;

    /** @MongoDB\Date(nullable=true) */
    protected $end;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->payments = new \Doctrine\Common\Collections\ArrayCollection();
        $this->status = self::STATUS_PENDING;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone(Phone $phone)
    {
        $this->phone = $phone;
    }

    public function getPayments()
    {
        return $this->payments;
    }

    public function addPayment($payment)
    {
        $this->payments->add($payment);
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function getStart()
    {
        return $this->start;
    }

    public function setStart(\DateTime $start)
    {
        $this->start = $start;
    }

    public function getEnd()
    {
        return $this->end;
    }

    public function setEnd(\DateTime $end)
    {
        $this->end = $end;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }
    
    public function getPolicyNumber()
    {
        return $this->policyNumber;
    }

    public function setPolicyNumber($policyNumber)
    {
        $this->policyNumber = $policyNumber;
    }

    public function getImei()
    {
        return $this->imei;
    }

    public function setImei($imei)
    {
        $this->imei = $imei;
    }

    public function activate()
    {
        $this->setStatus(self::STATUS_ACTIVE);
        $this->setStart(new \DateTime());
        $nextYear = clone $this->getStart();
        $nextYear = $nextYear->modify('+1 year');
        $this->setEnd($nextYear);
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
