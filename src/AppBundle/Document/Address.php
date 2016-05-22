<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable
 */
class Address
{
    const TYPE_BILLING = 'billing';
    public static $types = [self::TYPE_BILLING];

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
     * @MongoDB\String()
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @MongoDB\String()
     * @Gedmo\Versioned
     */
    protected $line1;

    /** @MongoDB\String() */
    protected $line2;

    /** @MongoDB\String() */
    protected $line3;

    /** @MongoDB\String() */
    protected $city;

    /** @MongoDB\String() */
    protected $postcode;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setType($type)
    {
        if (!in_array($type, self::$types)) {
            throw new \InvalidArgumentException('Type must be a valid type');
        }
        $this->type = $type;
    }

    public function getType()
    {
        return $this->type;
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

    public function __toString()
    {
        $lines = $this->getLine1();
        if (strlen($this->getLine3()) > 0) {
            $lines = sprintf("%s %s %s", $this->getLine1(), $this->getLine2(), $this->getLine3());
        } elseif (strlen($this->getLine2()) > 0) {
            $lines = sprintf("%s %s", $this->getLine1(), $this->getLine2());
        }
        return sprintf("%s %s %s", $lines, $this->getCity(), $this->getPostcode());
    }

    public function toApiArray()
    {
        return [
          'line1' => $this->getLine1(),
          'line2' => $this->getLine2(),
          'line3' => $this->getLine3(),
          'city' => $this->getCity(),
          'postcode' => $this->getPostcode(),
          'type' => $this->getType(),
        ];
    }
}
