<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Policy;

class Cancel
{
    /** @var Policy */
    protected $policy;
    
    /** @var string */
    protected $cancellationReason;

    /** @var string */
    protected $requestedCancellationReason;

    /** @var boolean */
    protected $skipNetworkEmail;

    /** @var boolean */
    protected $force;

    /** @var boolean */
    protected $fullRefund;

    public static function getEncodedCooloffReason($reason)
    {
        return sprintf('%s - %s', ucfirst(Policy::CANCELLED_COOLOFF), $reason);
    }

    public static function isEncodedCooloffReason($reason)
    {
        return mb_stripos($reason, self::getEncodedCooloffReason(null)) !== false;
    }

    public static function getDecodedCooloffReason($reason)
    {
        if (self::isEncodedCooloffReason($reason)) {
            return mb_substr($reason, mb_strlen(self::getEncodedCooloffReason(null)));
        } else {
            return null;
        }
    }

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
        if (self::isEncodedCooloffReason($cancellationReason)) {
            $this->cancellationReason = Policy::CANCELLED_COOLOFF;
            $this->setRequestedCancellationReason(self::getDecodedCooloffReason($cancellationReason));
        } else {
            $this->cancellationReason = $cancellationReason;
        }
    }

    public function getRequestedCancellationReason()
    {
        return $this->requestedCancellationReason;
    }

    public function setRequestedCancellationReason($requestedCancellationReason)
    {
        $this->requestedCancellationReason = $requestedCancellationReason;
    }

    public function getSkipNetworkEmail()
    {
        return $this->skipNetworkEmail;
    }

    public function setSkipNetworkEmail($skipNetworkEmail)
    {
        $this->skipNetworkEmail = $skipNetworkEmail;
    }

    public function getForce()
    {
        return $this->force;
    }

    public function setForce($force)
    {
        $this->force = $force;
    }

    public function getFullRefund()
    {
        return $this->fullRefund;
    }

    public function setFullRefund($fullRefund)
    {
        $this->fullRefund = $fullRefund;
    }
}
