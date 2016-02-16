<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class Payment
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /** @MongoDB\Date() */
    protected $created;
    
    /** @MongoDB\Float(name="amount", nullable=false) */
    protected $amount;

    /** @MongoDB\String(name="refererce", nullable=true) */
    protected $reference;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

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
}
