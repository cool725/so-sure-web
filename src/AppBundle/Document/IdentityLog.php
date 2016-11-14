<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable
 */
class IdentityLog
{
    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $cognitoId;

    /**
     * @Assert\Ip
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $ip;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $country;

    /**
     * @MongoDB\EmbedOne(targetDocument="Coordinates")
     * @Gedmo\Versioned
     */
    protected $loc;

    /** @MongoDB\Distance */
    public $distance;

    public function getCognitoId()
    {
        return $this->cognitoId;
    }

    public function setCognitoId($cognitoId)
    {
        $this->cognitoId = $cognitoId;
    }

    public function getIp()
    {
        return $this->ip;
    }

    public function setIp($ip)
    {
        $this->ip = $ip;
    }

    public function getCountry()
    {
        return $this->country;
    }

    public function setCountry($country)
    {
        $this->country = $country;
    }

    public function getLoc()
    {
        return $this->loc;
    }

    public function setLoc($loc)
    {
        $this->loc = $loc;
    }

    public function isSessionDataPresent()
    {
        return $this->getCognitoId() !== null || $this->getIp() !== null;
    }
}
