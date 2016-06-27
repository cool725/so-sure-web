<?php

namespace AppBundle\Document;

use AppBundle\Classes\Salva;
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
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * @MongoDB\Float()
     * @Gedmo\Versioned
     */
    protected $amount;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $gwp;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $ipt;

    /**
     * @MongoDB\Float()
     * @Gedmo\Versioned
     */
    protected $totalCommission;

    /**
     * @MongoDB\Float()
     * @Gedmo\Versioned
     */
    protected $coverholderCommission;

    /**
     * @MongoDB\Float()
     * @Gedmo\Versioned
     */
    protected $brokerCommission;

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
        $this->date = new \DateTime();
        $this->scheduledPayments = new \Doctrine\Common\Collections\ArrayCollection();
    }

    abstract public function isSuccess();

    public function getId()
    {
        return $this->id;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate(\DateTime $date)
    {
        $this->date = $date;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function setGwp($gwp)
    {
        $this->gwp = $gwp;
    }

    public function getGwp()
    {
        return $this->gwp;
    }

    public function setIpt($ipt)
    {
        $this->ipt = $ipt;
    }

    public function getIpt()
    {
        return $this->ipt;
    }

    public function calculateSplit()
    {
        $this->setIpt($this->getPolicy()->getPremium()->getIptRate() * $this->getAmount());
        $this->setGwp($this->getAmount() - $this->getIpt());
    }

    public function setTotalCommission($totalCommission)
    {
        $this->totalCommission = $totalCommission;
        if ($totalCommission == Salva::YEARLY_TOTAL_COMMISSION) {
            $this->coverholderCommission = Salva::YEARLY_COVERHOLDER_COMMISSION;
            $this->brokerCommission = Salva::YEARLY_BROKER_COMMISSION;
        } elseif ($totalCommission == Salva::MONTHLY_TOTAL_COMMISSION) {
            $this->coverholderCommission = Salva::MONTHLY_COVERHOLDER_COMMISSION;
            $this->brokerCommission = Salva::MONTHLY_BROKER_COMMISSION;
        } elseif ($totalCommission == Salva::FINAL_MONTHLY_TOTAL_COMMISSION) {
            $this->coverholderCommission = Salva::FINAL_MONTHLY_COVERHOLDER_COMMISSION;
            $this->brokerCommission = Salva::FINAL_MONTHLY_BROKER_COMMISSION;
        }
    }

    public function getTotalCommission()
    {
        return $this->totalCommission;
    }

    public function getCoverholderCommission()
    {
        return $this->coverholderCommission;
    }

    public function getBrokerCommission()
    {
        return $this->brokerCommission;
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
