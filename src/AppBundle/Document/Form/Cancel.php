<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Policy;

class Cancel
{
    /** @var Policy */
    protected $policy;
    
    /** @var string */
    protected $cancellationReason;

    /** @var boolean */
    protected $skipNetworkEmail;

    public function getPolicy()
    {
        return $this->policy;
    }
    
    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
    }
    
    public function getCancellationReason()
    {
        return $this->cancellationReason;
    }
    
    public function setCancellationReason($cancellationReason)
    {
        $this->cancellationReason = $cancellationReason;
    }

    public function getSkipNetworkEmail()
    {
        return $this->skipNetworkEmail;
    }

    public function setSkipNetworkEmail($skipNetworkEmail)
    {
        $this->skipNetworkEmail = $skipNetworkEmail;
    }
}
