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
     */
    protected $typeDetails;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="10", max="200")
     */
    protected $typeDetailsOther;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="4", max="200")
     */
    protected $monthOfPurchase;

    /**
     * @Assert\Range(min="2015", max="2050")
     */
    protected $yearOfPurchase;

    /**
     * @Assert\Choice({"new", "refurbished", "second-hand"}, strict=true)
     */
    protected $phoneStatus;

    protected $proofOfUsage;

    protected $pictureOfPhone;

    protected $other;

    protected $isSave;

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
    
    public function getPictureOfPhone()
    {
        return $this->pictureOfPhone;
    }
    
    public function setPictureOfPhone($pictureOfPhone)
    {
        $this->pictureOfPhone = $pictureOfPhone;
    }

    public function getOther()
    {
        return $this->other;
    }
    
    public function setOther($other)
    {
        $this->other = $other;
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
