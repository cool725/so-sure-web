<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class IdentityLog
{
    const SDK_ANDROID = 'android';
    const SDK_IOS = 'ios';
    const SDK_JAVASCRIPT = 'javascript';
    const SDK_WEB = 'web';
    const SDK_UNKNOWN = 'unknown';

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $date;

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
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $platform;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $sdk;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="20")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $version;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $uuid;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @Gedmo\Versioned
     */
    protected $phone;

    /**
     * @MongoDB\EmbedOne(targetDocument="Coordinates")
     * @Gedmo\Versioned
     */
    protected $loc;

    /** @MongoDB\Distance */
    public $distance;

    public function __construct()
    {
        $this->setDate(\DateTime::createFromFormat('U', time()));
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate(\DateTime $date)
    {
        $this->date = $date;
    }

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

    public function getUuid()
    {
        return $this->uuid;
    }

    public function setUuid($uuid)
    {
        $this->uuid = $uuid;
    }

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone(Phone $phone = null)
    {
        $this->phone = $phone;
    }

    public function getPlatform()
    {
        return $this->platform;
    }

    public function setPlatform($platform)
    {
        $this->platform = $platform;
    }

    public function getSdk()
    {
        return $this->sdk;
    }

    public function setSdk($sdk)
    {
        $this->sdk = $sdk;
    }
    
    public function getVersion()
    {
        return $this->version;
    }
    
    public function setVersion($version)
    {
        $this->version = $version;
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

    public function isApi()
    {
        return $this->getCognitoId() !== null;
    }

    public function viaText()
    {
        return $this->isApi() ? 'api' : 'web';
    }

    public function isSamePhone(Phone $phone = null)
    {
        if ($this->getPhone() && $phone) {
            return $this->getPhone()->getId() == $phone->getId();
        }

        return null;
    }
}
