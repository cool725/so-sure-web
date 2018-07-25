<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Claim;
use AppBundle\Document\DateTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Serializer;

class ClaimFnolUpdate
{
    use DateTrait;

    /**
     * @var Claim
     */
    protected $claim;

    protected $proofOfUsage;

    protected $pictureOfPhone;

    protected $proofOfBarring;

    protected $proofOfPurchase;

    protected $proofOfLoss;

    protected $other;

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

    public function getOther()
    {
        return $this->other;
    }
    
    public function setOther($other)
    {
        $this->other = $other;
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
