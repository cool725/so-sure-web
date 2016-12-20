<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class ClaimsCheck
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
     * @var string
     * @Assert\NotNull(message="Claims Type is required.")
     */
    protected $type;

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
