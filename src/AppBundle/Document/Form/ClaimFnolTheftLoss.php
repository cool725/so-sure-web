<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Claim;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ValidatorTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class ClaimFnolTheftLoss
{
    use DateTrait;
    use ValidatorTrait;

    /**
     * @var Claim
     */
    protected $claim;

    /**
     * @var boolean
     * @Assert\NotNull(message="Please select if you have contacted the place")
     */
    protected $hasContacted;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="4", max="200")
     * @Assert\NotNull(message="Please enter where you last had your phone")
     */
    protected $contactedPlace;

    /**
     * @Assert\DateTime()
     * @Assert\NotNull(message="Please enter when you blocked your phone")
     */
    protected $blockedDate;

    /**
     * @Assert\DateTime()
     * @Assert\NotNull(message="Please enter when you reported the loss")
     */
    protected $reportedDate;

    /**
     * @Assert\Choice({"police-station", "online"}, strict=true)
     * @Assert\NotNull(message="Please select where you reported the loss")
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
     * @Assert\IsTrue(message="You must upload your Proof of Usage document")
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
     * @Assert\IsTrue(message="You must upload your Proof of Barring document")
     */
    public function hasProofOfBarring()
    {
        if ($this->getClaim()->needProofOfBarring()) {
            return $this->getProofOfBarring() || count($this->getClaim()->getProofOfBarringFiles()) > 0;
        }

        return true;
    }

    /**
     * @Assert\IsTrue(message="You must upload your Proof of Purchase document")
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
     * @Assert\IsTrue(message="You must upload your Proof of Loss document")
     */
    public function hasProofOfLoss()
    {
        if ($this->getClaim()->getType() == Claim::TYPE_LOSS && $this->getReportType() == Claim::REPORT_ONLINE) {
            return $this->getProofOfLoss() || count($this->getClaim()->getProofOfLossFiles()) > 0;
        } else {
            return true;
        }
    }

    /**
     * @Assert\IsTrue(message="Please select a police force")
     */
    public function hasForce()
    {
        if ($this->getReportType() == Claim::REPORT_POLICE_STATION) {
            return mb_strlen($this->getForce()) > 0;
        } else {
            return true;
        }
    }

    /**
     * @Assert\IsTrue(message="Please enter a valid crime reference number")
     */
    public function hasCrimeReferenceNumber()
    {
        if ($this->getReportType() == Claim::REPORT_POLICE_STATION) {
            return mb_strlen($this->getCrimeReferenceNumber()) > 0;
        } else {
            return true;
        }
    }

    public function setClaim(Claim $claim)
    {
        $this->claim = $claim;
        $this->hasContacted = $claim->getHasContacted();
        $this->contactedPlace = $this->conformAlphanumericSpaceDot($claim->getContactedPlace(), 200, 4);
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
