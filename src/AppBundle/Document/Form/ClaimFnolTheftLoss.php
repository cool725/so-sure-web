<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Claim;
use AppBundle\Document\DateTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class ClaimFnolTheftLoss
{
    use DateTrait;

    /**
     * @var Claim
     */
    protected $claim;

    /**
     * @var boolean
     */
    protected $hasContacted;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="4", max="200")
     */
    protected $contactedPlace;

    /**
     * @Assert\DateTime()
     */
    protected $blockedDate;

    /**
     * @Assert\DateTime()
     */
    protected $reportedDate;

    /**
     * @Assert\Choice({"police-station", "online"}, strict=true)
     */
    protected $reportType;

    protected $proofOfUsage;

    protected $proofOfBarring;

    protected $proofOfPurchase;

    protected $proofOfLoss;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     */
    protected $crimeReferenceNumber;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     */
    protected $policeLossReport;

    protected $isSave;

    public function getHasContacted()
    {
        return $this->hasContacted;
    }

    public function setHasContacted($hasContacted)
    {
        $this->hasContacted = $hasContacted;
    }

    public function getContactedPlace()
    {
        return $this->contactedPlace;
    }

    public function setContactedPlace($contactedPlace)
    {
        $this->contactedPlace = $contactedPlace;
    }

    public function getBlockedDate()
    {
        return $this->blockedDate;
    }

    public function setBlockedDate($blockedDate)
    {
        $this->blockedDate = $blockedDate;
    }
    
    public function getReportedDate()
    {
        return $this->reportedDate;
    }

    public function setReportedDate($reportedDate)
    {
        $this->reportedDate = $reportedDate;
    }

    public function getReportType()
    {
        return $this->reportType;
    }

    public function setReportType($reportType)
    {
        $this->reportType = $reportType;
    }

    public function getProofOfUsage()
    {
        return $this->proofOfUsage;
    }
    
    public function setProofOfUsage($proofOfUsage)
    {
        $this->proofOfUsage = $proofOfUsage;
    }

    public function getProofOfBarring()
    {
        return $this->proofOfBarring;
    }
    
    public function setProofOfBarring($proofOfBarring)
    {
        $this->proofOfBarring = $proofOfBarring;
    }

    public function getProofOfPurchase()
    {
        return $this->proofOfPurchase;
    }
    
    public function setProofOfPurchase($proofOfPurchase)
    {
        $this->proofOfPurchase = $proofOfPurchase;
    }

    public function getProofOfLoss()
    {
        return $this->proofOfLoss;
    }

    public function setProofOfLoss($proofOfLoss)
    {
        $this->proofOfLoss = $proofOfLoss;
    }

    public function getCrimeReferenceNumber()
    {
        return $this->crimeReferenceNumber;
    }
    
    public function setCrimeReferenceNumber($crimeReferenceNumber)
    {
        $this->crimeReferenceNumber = $crimeReferenceNumber;
    }

    public function getPoliceLossReport()
    {
        return $this->policeLossReport;
    }
    
    public function setPoliceLossReport($policeLossReport)
    {
        $this->policeLossReport = $policeLossReport;
    }

    public function getIsSave()
    {
        return $this->isSave;
    }
    
    public function setIsSave($isSave)
    {
        $this->isSave = $isSave;
    }

    public function getClaim()
    {
        return $this->claim;
    }
    
    public function setClaim(Claim $claim)
    {
        $this->claim = $claim;
        $this->hasContacted = $claim->getHasContacted();
        $this->contactedPlace = $claim->getContactedPlace();
        $this->blockedDate = $claim->getBlockedDate();
        $this->reportedDate = $claim->getReportedDate();
        $this->reportType = $claim->getReportType();
        if (!$claim->getReportType() && $claim->getType() == Claim::TYPE_LOSS) {
            $this->reportType = Claim::REPORT_POLICE_STATION;
        }
        $this->crimeReferenceNumber = $claim->getCrimeRef();
        $this->policeLossReport = $claim->getPoliceLossReport();
    }
}
