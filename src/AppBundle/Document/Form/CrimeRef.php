<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class CrimeRef
{
    /**
     * @var Policy
     * @Assert\NotNull(message="Policy is required.")
     */
    protected $policy;

    /**
     * @var Claim
     * @Assert\NotNull(message="Claim is required.")
     */
    protected $claim;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     */
    protected $force;

    /**
     * @Assert\Length(min="1", max="50")
     */
    protected $crimeRef;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     */
    protected $type;

    public function getForce()
    {
        return $this->force;
    }

    public function setForce($force)
    {
        $this->force = $force;
    }

    public function getCrimeRef()
    {
        return $this->crimeRef;
    }

    public function setCrimeRef($crimeRef)
    {
        $this->crimeRef = $crimeRef;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
    }

    public function getClaim()
    {
        return $this->claim;
    }

    public function setClaim($claim)
    {
        $this->claim = $claim;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type)
    {
        $this->type = $type;
    }
}
