<?php
/**
 * Created by PhpStorm.
 * User: patrick
 * Date: 05/10/2018
 * Time: 11:24
 */

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable
 */
class AffiliateCompany extends Company
{

    /**
     * @Assert\Range(min=0,max=20)
     * @MongoDB\Field(type="float")
     */
    protected $cpa;

    /**
     * @Assert\Range(min=0,max=90)
     * @MongoDB\Field(type="integer")
     */
    protected $days;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $campaignSource;

    /**
     * @Assert\Choice({"invitation", "scode", "affiliate"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $leadSource;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $leadSourceDetails;

    public function setCPA(float $cpa)
    {
        $this->cpa = $cpa;
    }

    public function getCPA()
    {
        return $this->cpa;
    }

    public function setDays(int $days)
    {
        $this->days = $days;
    }

    public function getDays()
    {
        return $this->days;
    }

    public function getCampaignSource()
    {
        return $this->campaignSource;
    }

    public function setCampaignSource(String $campaignSource)
    {
        $this->campaignSource = $campaignSource;
    }

    public function getLeadSource()
    {
        return $this->leadSource;
    }

    public function setLeadSource(String $leadSource)
    {
        $this->leadSource = $leadSource;
    }

    public function getLeadSourceDetails()
    {
        return $this->leadSourceDetails;
    }

    public function setLeadSourceDetails(String $leadSourceDetails)
    {
        $this->leadSourceDetails = $leadSourceDetails;
    }

}
