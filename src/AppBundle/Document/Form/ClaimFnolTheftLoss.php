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
    protected $force;

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

    public function getForce()
    {
        return $this->force;
    }
    
    public function setForce($force)
    {
        $this->force = $force;
    }

    public function getClaim()
    {
        return $this->claim;
    }

    /**
     * @Assert\IsTrue(message="At least one Proof of Usage File must be uploaded")
     */
    public function hasProofOfUsage()
    {
        if ($this->getClaim()->needProofOfUsage()) {
            return $this->getProofOfUsage() || count($this->getClaim()->getProofOfUsageFiles()) > 0;
        } else {
            return true;
        }
    }

    /**
     * @Assert\IsTrue(message="At least one Proof of Barring File must be uploaded")
     */
    public function hasProofOfBarring()
    {
        if ($this->getClaim()->needProofOfBarring()) {
            return $this->getProofOfBarring() || count($this->getClaim()->getProofOfBarringFiles()) > 0;
        }

        return true;
    }

    /**
     * @Assert\IsTrue(message="At least one Proof of Purchase File must be uploaded")
     */
    public function hasProofOfPurchase()
    {
        if ($this->getClaim()->needProofOfPurchase()) {
            return $this->getProofOfPurchase() || count($this->getClaim()->getProofOfPurchaseFiles()) > 0;
        } else {
            return true;
        }
    }

    /**
     * @Assert\IsTrue(message="At least one Proof of Loss File must be uploaded")
     */
    public function hasProofOfLoss()
    {
        if ($this->getClaim()->needProofOfLoss()) {
            return $this->getProofOfLoss() || count($this->getClaim()->getProofOfLossFiles()) > 0;
        } else {
            return true;
        }
    }

    public function setClaim(Claim $claim)
    {
        $this->claim = $claim;
        $this->hasContacted = $claim->getHasContacted();
        $this->contactedPlace = $claim->getContactedPlace();
        $this->blockedDate = $claim->getBlockedDate();
        $this->reportedDate = $claim->getReportedDate();
        $this->reportType = $claim->getReportType();
        if (!$claim->getReportType() && $claim->getType() == Claim::TYPE_THEFT) {
            $this->reportType = Claim::REPORT_POLICE_STATION;
        }
        $this->crimeReferenceNumber = $claim->getCrimeRef();
        $this->force = $claim->getForce();
    }
}
