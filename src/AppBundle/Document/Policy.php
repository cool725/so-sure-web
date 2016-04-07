<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("policy_type")
 * @MongoDB\DiscriminatorMap({"phone"="PhonePolicy"})
 */
abstract class Policy
{
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_UNPAID = 'unpaid';

    const PAYMENT_DD_MONTHLY = 'gocardless_monthly';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

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

    /** @MongoDB\Field(type="string", name="policy_number", nullable=true) */
    protected $policyNumber;

    /** @MongoDB\Field(type="string", name="payment_type", nullable=true) */
    protected $paymentType;

    /** @MongoDB\Field(type="string", name="gocardless_mandate", nullable=true) */
    protected $gocardlessMandate;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Invitation\Invitation", mappedBy="policy")
     */
    protected $invitations;

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
        $this->invitations = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
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

    public function getPaymentType()
    {
        return $this->paymentType;
    }

    public function setPaymentType($paymentType)
    {
        $this->paymentType = $paymentType;
    }

    public function getGocardlessMandate()
    {
        return $this->gocardlessMandate;
    }

    public function setGocardlessMandate($gocardlessMandate)
    {
        $this->gocardlessMandate = $gocardlessMandate;
    }

    public function activate()
    {
        $this->setStatus(self::STATUS_ACTIVE);
        $this->setStart(new \DateTime());
        $nextYear = clone $this->getStart();
        $nextYear = $nextYear->modify('+1 year');
        $this->setEnd($nextYear);
    }
}
