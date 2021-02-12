<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Claim;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class ClaimFnolDamage
{

    /**
     * @var Claim
     */
    protected $claim;

    /**
     * @Assert\Choice({"broken-screen", "water-damage", "out-of-warranty-breakdown", "other"}, strict=true)
     * @Assert\NotNull(message="Please select the type of damage")
     */
    protected $typeDetails;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="200")
     */
    protected $typeDetailsOther;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="3", max="200")
     * @Assert\NotNull(message="Please select a month")
     */
    protected $monthOfPurchase;

    /**
     * 2016 is launched year - allowed 3 years gives 2013
     * TODO: Adjust to use a max of current year as a specialised validator
     * @Assert\Range(min="2013", max="2050")
     * @Assert\NotNull(message="Please enter a year")
     */
    protected $yearOfPurchase;

    /**
     * @Assert\Choice({"new", "refurbished", "second-hand"}, strict=true)
     * @Assert\NotNull(message="Please select a condition")
     */
    protected $phoneStatus;

    protected $proofOfUsage;

    protected $proofOfPurchase;

    protected $pictureOfPhone;

    public function getTypeDetails()
    {
        return $this->typeDetails;
    }

    public function setTypeDetails($typeDetails)
    {
        $this->typeDetails = $typeDetails;
    }

    public function getTypeDetailsOther()
    {
        return $this->typeDetailsOther;
    }

    public function setTypeDetailsOther($typeDetailsOther)
    {
        $this->typeDetailsOther = $typeDetailsOther;
    }

    public function getMonthOfPurchase()
    {
        return $this->monthOfPurchase;
    }

    public function setMonthOfPurchase($monthOfPurchase)
    {
        $this->monthOfPurchase = $monthOfPurchase;
    }

    public function getYearOfPurchase()
    {
        return $this->yearOfPurchase;
    }

    public function setYearOfPurchase($yearOfPurchase)
    {
        $this->yearOfPurchase = $yearOfPurchase;
    }

    public function getPhoneStatus()
    {
        return $this->phoneStatus;
    }

    public function setPhoneStatus($phoneStatus)
    {
        $this->phoneStatus = $phoneStatus;
    }

    public function getProofOfUsage()
    {
        return $this->proofOfUsage;
    }

    public function setProofOfUsage($proofOfUsage)
    {
        $this->proofOfUsage = $proofOfUsage;
    }

    public function getProofOfPurchase()
    {
        return $this->proofOfPurchase;
    }

    public function setProofOfPurchase($proofOfPurchase)
    {
        $this->proofOfPurchase = $proofOfPurchase;
    }

    public function getPictureOfPhone()
    {
        return $this->pictureOfPhone;
    }

    public function setPictureOfPhone($pictureOfPhone)
    {
        $this->pictureOfPhone = $pictureOfPhone;
    }

    public function getClaim()
    {
        return $this->claim;
    }

    // /**
    //  * @Assert\IsTrue(message="You must upload your Proof of Usage document")
    //  */
    // public function hasProofOfUsage()
    // {
    //     if ($this->getClaim()->needProofOfUsage()) {
    //         return $this->getProofOfUsage() || count($this->getClaim()->getProofOfUsageFiles()) > 0;
    //     } else {
    //         return true;
    //     }
    // }

    /**
     * @Assert\IsTrue(message="You must upload your Proof of Damage document")
     */
    public function hasPictureOfPhone()
    {
        if ($this->getClaim()->needPictureOfPhone()) {
            return $this->getPictureOfPhone() || count($this->getClaim()->getDamagePictureFiles()) > 0;
        } else {
            return true;
        }
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
     * @Assert\IsTrue(message="Please explain further")
     */
    public function hasTypeDetailsOther()
    {
        if ($this->getTypeDetails() == "other") {
            return mb_strlen($this->getTypeDetailsOther()) > 0;
        } else {
            return true;
        }
    }

    public function setClaim($claim)
    {
        $this->claim = $claim;
        $this->typeDetails = $claim->getTypeDetails();
        $this->typeDetailsOther = $claim->getTypeDetailsOther();
        $this->monthOfPurchase = $claim->getMonthOfPurchase();
        $this->yearOfPurchase = $claim->getYearOfPurchase();
        $this->phoneStatus = $claim->getPhoneStatus();
    }
}
