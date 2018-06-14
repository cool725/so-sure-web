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
     * @Assert\Range(min="1", max="12")
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

    /**
     * @var boolean
     */
    protected $isUnderWarranty;

    protected $proofOfUsage;

    protected $pictureOfPhone;

    public function getTypeDetails()
    {
        return $this->email;
    }

    public function setTypeDetails($typeDetails)
    {
        $this->typeDetails = $typeDetails;
    }

    public function getTypeDetailsOther()
    {
        return $this->name;
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

    public function getIsUnderWarranty()
    {
        return $this->isUnderWarranty;
    }
    
    public function setIsUnderWarranty($isUnderWarranty)
    {
        $this->isUnderWarranty = $isUnderWarranty;
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

    public function getClaim()
    {
        return $this->claim;
    }
    
    public function setClaim($claim)
    {
        $this->claim = $claim;
    }
}
