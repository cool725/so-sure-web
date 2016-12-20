<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ChargeRepository")
 * @Gedmo\Loggable
 */
class Charge
{
    const TYPE_ADDRESS = 'address';
    const TYPE_SMS = 'sms';
    const TYPE_GSMA = 'gsma';
    const TYPE_MAKEMODEL = 'makemodel';
    const TYPE_CLAIMSCHECK = 'claimscheck';

    public static $prices = [
        self::TYPE_ADDRESS => 0.037, // ex vat
        self::TYPE_SMS => 0.03, // $0.03 (not vat)
        self::TYPE_GSMA => 0.02, // ex vat
        self::TYPE_MAKEMODEL => 0.05, // ex vat
        self::TYPE_CLAIMSCHECK => 0.9, // ex vat
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $createdDate;

    /**
     * @Assert\Choice({"address", "sms", "gsma", "makemodel", "claimscheck"})
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     * @Gedmo\Versioned
     */
    protected $user;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     */
    protected $policy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Claim")
     * @Gedmo\Versioned
     */
    protected $claim;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     */
    public $handler;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $amount = 0;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $details;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Invoice")
     * @Gedmo\Versioned
     */
    protected $invoice;

    public function __construct()
    {
        $this->createdDate = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCreatedDate()
    {
        return $this->createdDate;
    }

    public function setCreatedDate($createdDate)
    {
        $this->createdDate = $createdDate;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser($user)
    {
        $this->user = $user;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    public function getClaim()
    {
        return $this->claim;
    }

    public function setClaim($claim)
    {
        $this->claim = $claim;
    }

    public function getHandler()
    {
        return $this->handler;
    }

    public function setHandler($handler)
    {
        $this->handler = $handler;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type)
    {
        $this->type = $type;
        $this->setAmount(self::$prices[$type]);
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;
    }

    public function getDetails()
    {
        return $this->details;
    }

    public function setDetails($details)
    {
        $this->details = $details;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function setInvoice($invoice)
    {
        $this->invoice = $invoice;
    }
}
