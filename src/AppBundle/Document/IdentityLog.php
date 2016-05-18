<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable
 */
class IdentityLog
{
    /**
     * @MongoDB\String(name="cognito_id", nullable=true)
     * @Gedmo\Versioned
     */
    protected $cognitoId;

    /**
     * @MongoDB\String(name="ip", nullable=true)
     * @Gedmo\Versioned
     */
    protected $ip;

    /**
     * @MongoDB\String(name="country", nullable=true)
     * @Gedmo\Versioned
     */
    protected $country;

    /**
     * @MongoDB\EmbedOne(targetDocument="Coordinates", name="loc")
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
}
