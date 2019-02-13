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
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotPipeValidator;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Attribution implements EqualsInterface
{
    use DateTrait;

    const SOURCE_UNTRACKED = 'untracked';
    const SOURCE_ACCOUNT_KIT = 'www.accountkit.com';
    const SOURCE_JUDOPAY = 'pay.judopay.com';

    const SOURCE_GOOGLE_SEARCH = 'www.google.co.uk';

    const SOURCE_GOOGLE = 'google';
    const SOURCE_GOOGLE_ADWORDS = 'google adwords';
    const SOURCE_BING = 'bing';

    const SOURCE_EMAIL = 'email';
    const SOURCE_INTERCOM = 'intercom';
    const SOURCE_APP = 'app';

    // comparision
    const SOURCE_MONEY_CO_UK = 'money.co.uk';
    const SOURCE_QUOTEZONE = 'quotezone';
    const SOURCE_BOUGHTBYMANY = 'boughtbymany';

    public static $sourceGroupUntracked = [
        self::SOURCE_UNTRACKED,
        self::SOURCE_ACCOUNT_KIT,
        self::SOURCE_JUDOPAY,
    ];

    public static $sourceGroupBing = [
        self::SOURCE_BING,
    ];

    public static $sourceGroupGoogle = [
        self::SOURCE_GOOGLE,
        self::SOURCE_GOOGLE_ADWORDS,
    ];

    public static $sourceGroupComparison = [
        self::SOURCE_MONEY_CO_UK,
        self::SOURCE_QUOTEZONE,
    ];

    public static $sourceGroupAffiliate = [
        self::SOURCE_BOUGHTBYMANY,
    ];

    public static $sourceGroupOther = [
        self::SOURCE_APP,
        self::SOURCE_EMAIL,
        self::SOURCE_INTERCOM,
        self::SOURCE_GOOGLE_SEARCH,
    ];

    /**
     * @AppAssert\AlphanumericSpaceDotPipe()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $campaignName;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $campaignSource;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $campaignMedium;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $campaignTerm;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $campaignContent;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="1500")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $referer;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="1500")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $goCompareQuote;

    /**
     * Make sure to sync choices with RequestService
     * @Assert\Choice({"Desktop", "Tablet", "Mobile"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $deviceCategory;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $deviceOS;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $date;

    public function setCampaignName($campaignName)
    {
        $this->campaignName = $campaignName;
    }

    public function getCampaignName()
    {
        return $this->campaignName;
    }

    public function setCampaignSource($campaignSource)
    {
        $this->campaignSource = $campaignSource;
    }

    public function getCampaignSource()
    {
        return $this->campaignSource;
    }

    public function setCampaignMedium($campaignMedium)
    {
        $this->campaignMedium = $campaignMedium;
    }

    public function getCampaignMedium()
    {
        return $this->campaignMedium;
    }

    public function setCampaignTerm($campaignTerm)
    {
        $this->campaignTerm = $campaignTerm;
    }

    public function getCampaignTerm()
    {
        return $this->campaignTerm;
    }

    public function setCampaignContent($campaignContent)
    {
        $this->campaignContent = $campaignContent;
    }

    public function getCampaignContent()
    {
        return $this->campaignContent;
    }

    public function setReferer($referer)
    {
        $validator = new AlphanumericSpaceDotValidator();

        $this->referer = $validator->conform(mb_substr($referer, 0, 1500));
    }

    public function getReferer()
    {
        return $this->referer;
    }

    public function getGoCompareQuote()
    {
        return $this->goCompareQuote;
    }

    public function setGoCompareQuote($goCompareQuote)
    {
        $this->goCompareQuote = $goCompareQuote;
    }

    public function setDeviceCategory($deviceCategory)
    {
        $this->deviceCategory = $deviceCategory;
    }

    public function getDeviceCategory()
    {
        return $this->deviceCategory;
    }

    public function setDeviceOS($deviceOS)
    {
        $this->deviceOS = $deviceOS;
    }

    public function getDeviceOS()
    {
        return $this->deviceOS;
    }

    public function setDate(\DateTime $date)
    {
        $this->date = $date;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function equals($attribution)
    {
        if (!$attribution) {
            return false;
        }

        return $this->__toString() == $attribution->__toString();
    }

    public function __toString()
    {
        return $this->stringImplode(' / ');
    }

    public function stringImplode($glue)
    {
        $lines = [];
        if (mb_strlen($this->getCampaignName()) > 0) {
            $lines[] = sprintf("Name: %s", $this->getCampaignName());
        }
        if (mb_strlen($this->getCampaignSource()) > 0) {
            $lines[] = sprintf("Source: %s", $this->getCampaignSource());
        }
        if (mb_strlen($this->getCampaignMedium()) > 0) {
            $lines[] = sprintf("Medium: %s", $this->getCampaignMedium());
        }
        if (mb_strlen($this->getCampaignContent()) > 0) {
            $lines[] = sprintf("Content: %s", $this->getCampaignContent());
        }
        if (mb_strlen($this->getCampaignTerm()) > 0) {
            $lines[] = sprintf("Term: %s", $this->getCampaignTerm());
        }
        if (mb_strlen($this->getReferer()) > 0) {
            $lines[] = sprintf("Referer: %s", $this->getReferer());
        }
        if (mb_strlen($this->getDeviceCategory()) > 0) {
            $lines[] = sprintf("Device Category: %s", $this->getDeviceCategory());
        }
        if (mb_strlen($this->getDeviceOS()) > 0) {
            $lines[] = sprintf("Device OS: %s", $this->getDeviceOS());
        }

        return implode($glue, $lines);
    }

    public function getNormalizedCampaignSource()
    {
        $source = mb_strtolower(trim($this->getCampaignSource()));
        if (mb_strlen(trim($source)) == 0) {
            $source = self::SOURCE_UNTRACKED;
        }

        // historical data seems to have issues with urls as source
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $source = parse_url($source, PHP_URL_HOST);
        }

        return $source;
    }

    public function getCampaignSourceGroup()
    {
        $source = $this->getNormalizedCampaignSource();
        if (in_array($source, self::$sourceGroupUntracked)) {
            return self::SOURCE_UNTRACKED;
        } elseif (in_array($source, self::$sourceGroupAffiliate)) {
            return 'Affiliate';
        } elseif (in_array($source, self::$sourceGroupBing)) {
            return 'Bing';
        } elseif (in_array($source, self::$sourceGroupGoogle)) {
            return 'Adwords';
        } elseif (in_array($source, self::$sourceGroupComparison)) {
            return 'Comparison';
        } elseif (in_array($source, self::$sourceGroupOther)) {
            return 'Other';
        }

        return $source;
    }

    public function getMixpanelProperties($prefix = '')
    {
        $data = [];
        if ($this->getCampaignSource()) {
            $data[sprintf('%sCampaign Source', $prefix)] = $this->getCampaignSource();
        }
        if ($this->getCampaignMedium()) {
            $data[sprintf('%sCampaign Medium', $prefix)] = $this->getCampaignMedium();
        }
        if ($this->getCampaignName()) {
            $data[sprintf('%sCampaign Name', $prefix)] = $this->getCampaignName();
        }
        if ($this->getCampaignTerm()) {
            $data[sprintf('%sCampaign Term', $prefix)] = $this->getCampaignTerm();
        }
        if ($this->getCampaignContent()) {
            $data[sprintf('%sCampaign Content', $prefix)] = $this->getCampaignContent();
        }

        if ($this->getReferer()) {
            $transform[sprintf('%sReferer', $prefix)] = $this->getReferer();
        }

        if ($this->getDeviceCategory()) {
            $transform[sprintf('%sDevice Category', $prefix)] = $this->getDeviceCategory();
        }

        if ($this->getDeviceOS()) {
            $transform[sprintf('%sDevice OS', $prefix)] = $this->getDeviceOS();
        }

        $data[sprintf('%sCampaign Attribution Date', $prefix)] = $this->now()->format(\DateTime::ISO8601);

        return $data;
    }
}
