<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use AppBundle\Interfaces\EqualsInterface;
use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use VasilDakov\Postcode\Postcode;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable
 */
class Address implements EqualsInterface
{
    const TYPE_BILLING = 'billing';
    public static $types = [self::TYPE_BILLING];

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * @Assert\Choice({"billing"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $line1;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $line2;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $line3;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $city;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $postcode;

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
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
        $postcode = new Postcode($postcode);
        $this->postcode = $postcode->normalise();
    }

    public function getPostcode()
    {
        return $this->postcode;
    }

    public function __toString()
    {
        return $this->stringImplode(' ');
    }

    public function stringImplode($glue)
    {
        $lines = [];
        if (mb_strlen($this->getLine1()) > 0) {
            $lines[] = $this->getLine1();
        }
        if (mb_strlen($this->getLine2()) > 0) {
            $lines[] = $this->getLine2();
        }
        if (mb_strlen($this->getLine3()) > 0) {
            $lines[] = $this->getLine3();
        }
        if (mb_strlen($this->getCity()) > 0) {
            $lines[] = $this->getCity();
        }
        if (mb_strlen($this->getPostcode()) > 0) {
            $lines[] = $this->getPostcode();
        }

        return implode($glue, $lines);
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

    public function equals($compare)
    {
        if (!$compare || !$compare instanceof Address) {
            return false;
        }

        return serialize($this->toApiArray()) == serialize($compare->toApiArray());
    }
}
