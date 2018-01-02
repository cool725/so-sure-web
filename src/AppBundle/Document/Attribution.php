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
}
