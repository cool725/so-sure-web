<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class Address
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\String(name="line1", nullable=false) */
    protected $line1;

    /** @MongoDB\String(name="line2", nullable=true) */
    protected $line2;

    /** @MongoDB\String(name="line3", nullable=true) */
    protected $line3;

    /** @MongoDB\String(name="line4", nullable=true) */
    protected $line4;

    /** @MongoDB\String(name="line5", nullable=true) */
    protected $line5;

    /** @MongoDB\String(name="city", nullable=false) */
    protected $city;

    /** @MongoDB\String(name="postcode", nullable=false) */
    protected $postcode;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     */
    protected $user;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setLine1($line1)
    {
        $this->line1 = $line1;
    }

    public function getLine1()
    {
        return $this->line1;
    }

    public function setLine2($line2)
    {
        $this->line2 = $line2;
    }

    public function getLine2()
    {
        return $this->line2;
    }

    public function setLine3($line3)
    {
        $this->line3 = $line3;
    }

    public function getLine3()
    {
        return $this->line3;
    }

    public function setLine4($line4)
    {
        $this->line4 = $line4;
    }

    public function getLine4()
    {
        return $this->line4;
    }

    public function setLine5($line5)
    {
        $this->line5 = $line5;
    }

    public function getLine5()
    {
        return $this->line5;
    }

    public function setCity($city)
    {
        $this->city = $city;
    }

    public function getCity()
    {
        return $this->city;
    }

    public function setPostcode($postcode)
    {
        $this->postcode = $postcode;
    }

    public function getPostcode()
    {
        return $this->postcode;
    }

    public function toArray()
    {
        return [
          'line1' => $this->getLine1(),
          'line2' => $this->getLine2(),
          'line3' => $this->getLine3(),
          'line4' => $this->getLine4(),
          'line5' => $this->getLine5(),
          'city' => $this->getCity(),
          'postcode' => $this->getPostcode(),
        ];
    }
}
