<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

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
 * @Gedmo\Loggable
 */
class Attribution
{
    const SOURCE_UNTRACKED = 'untracked';
    const SOURCE_ACCOUNT_KIT = 'www.accountkit.com';
    const SOURCE_JUDOPAY = 'pay.judopay.com';

    const SOURCE_GOOGLE_SEARCH = 'www.google.co.uk';

    const SOURCE_GOOGLE = 'goggle';
    const SOURCE_GOOGLE_ADWORDS = 'google+adwords';
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
        self::SOURCE_BOUGHTBYMANY,
    ];

    public static $sourceGroupAffiliate = [
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

        $this->referer = $validator->conform(substr($referer, 0, 1500));
    }

    public function getReferer()
    {
        return $this->referer;
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
        if (strlen($this->getCampaignName()) > 0) {
            $lines[] = sprintf("Name: %s", $this->getCampaignName());
        }
        if (strlen($this->getCampaignSource()) > 0) {
            $lines[] = sprintf("Source: %s", $this->getCampaignSource());
        }
        if (strlen($this->getCampaignMedium()) > 0) {
            $lines[] = sprintf("Medium: %s", $this->getCampaignMedium());
        }
        if (strlen($this->getCampaignContent()) > 0) {
            $lines[] = sprintf("Content: %s", $this->getCampaignContent());
        }
        if (strlen($this->getCampaignTerm()) > 0) {
            $lines[] = sprintf("Term: %s", $this->getCampaignTerm());
        }
        if (strlen($this->getReferer()) > 0) {
            $lines[] = sprintf("Referer: %s", $this->getReferer());
        }

        return implode($glue, $lines);
    }

    public function getNormalizedCampaignSource()
    {
        $source = strtolower($this->getCampaignSource());
        if (strlen(trim($source)) == 0) {
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
}
