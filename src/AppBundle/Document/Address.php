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

    /** @MongoDB\String(name="address1", nullable=false) */
    protected $address1;

    /** @MongoDB\String(name="address1", nullable=true) */
    protected $address2;

    /** @MongoDB\String(name="address1", nullable=true) */
    protected $address3;

    /** @MongoDB\String(name="address1", nullable=true) */
    protected $address4;

    /** @MongoDB\String(name="address1", nullable=true) */
    protected $address5;

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

    public function setAddress1($address1)
    {
        $this->address1 = $address1;
    }

    public function getAddress1()
    {
        return $this->address1;
    }

    public function setAddress2($address2)
    {
        $this->address2 = $address2;
    }

    public function getAddress2()
    {
        return $this->address2;
    }

    public function setAddress3($address3)
    {
        $this->address3 = $address3;
    }

    public function getAddress3()
    {
        return $this->address3;
    }

    public function setAddress4($address4)
    {
        $this->address4 = $address4;
    }

    public function getAddress4()
    {
        return $this->address4;
    }

    public function setAddress5($address5)
    {
        $this->address5 = $address5;
    }

    public function getAddress5()
    {
        return $this->address5;
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
          'address1' => $this->getAddress1(),
          'address2' => $this->getAddress2(),
          'address3' => $this->getAddress3(),
          'address4' => $this->getAddress4(),
          'address5' => $this->getAddress5(),
          'city' => $this->getCity(),
          'postcode' => $this->getPostcode(),
        ];
    }
}
