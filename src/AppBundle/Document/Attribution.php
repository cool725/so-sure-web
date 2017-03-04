<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

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
class Attribution
{
    /**
     * @AppAssert\AlphanumericSpaceDot()
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
        $this->referer = $referer;
    }

    public function getReferer()
    {
        return $this->referer;
    }
}
