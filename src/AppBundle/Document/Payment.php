<?php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"judo"="JudoPayment","gocardless"="GocardlessPayment"})
 * @Gedmo\Loggable
 */
abstract class Payment
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * @MongoDB\Float()
     * @Gedmo\Versioned
     */
    protected $amount;

    /**
     * @MongoDB\String()
     * @MongoDB\Index(sparse=true)
     * @Gedmo\Versioned
     */
    protected $reference;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy", inversedBy="payments")
     * @Gedmo\Versioned
     */
    protected $policy;

    /**
     * @MongoDB\String()
     * @Gedmo\Versioned
     */
    protected $receipt;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="payments")
     * @Gedmo\Versioned
     */
    protected $user;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\ScheduledPayment")
     */
    protected $scheduledPayments;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->scheduledPayments = new \Doctrine\Common\Collections\ArrayCollection();
    }

    abstract public function isSuccess();

    public function getId()
    {
        return $this->id;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;
    }
    
    public function getAmount()
    {
        return $this->amount;
    }

    public function setReference($reference)
    {
        $this->reference = $reference;
    }
    
    public function getReference()
    {
        return $this->reference;
    }
    
    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function getToken()
    {
        return $this->token;
    }

    public function setToken($token)
    {
        $this->token = $token;
    }

    public function getReceipt()
    {
        return $this->receipt;
    }

    public function setReceipt($receipt)
    {
        $this->receipt = $receipt;
    }

    public function getScheduledPayments()
    {
        return $this->scheduledPayments;
    }

    public function addScheduledPayment(ScheduledPayment $scheduledPayment)
    {
        $scheduledPayment->setPayment($this);
        $this->scheduledPayments[] = $scheduledPayment;
    }
}
