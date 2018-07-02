<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Claim;
use AppBundle\Document\DateTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class ClaimFnolUpdate
{
    use DateTrait;

    /**
     * @var Claim
     */
    protected $claim;

    /**
     * @Assert\DateTime()
     */
    protected $when;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="4", max="100")
     */
    protected $time;

    protected $proofOfUsage;

    protected $pictureOfPhone;

    protected $proofOfBarring;

    protected $proofOfPurchase;

    public function getWhen($normalised = false)
    {
        if ($normalised) {
            $serializer = new Serializer(array(new DateTimeNormalizer()));
            return $serializer->normalize($this->when);
        } else {
            return $this->when;
        }
    }

    public function setWhen($when)
    {
        $this->when = $when;
    }

    public function getTime()
    {
        return $this->time;
    }

    public function setTime($time)
    {
        $this->time = $time;
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

    public function getClaim()
    {
        return $this->claim;
    }
    
    public function setClaim($claim)
    {
        $this->claim = $claim;
        $this->when = $claim->getIncidentDate();
        $this->time = $claim->getIncidentTime();
    }
}
