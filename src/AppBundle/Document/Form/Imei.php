<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\DateTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Imei
{
    /**
     * @var Policy
     * @Assert\NotNull(message="Policy is required.")
     */
    protected $policy;

    protected $imei;

    public function getImei()
    {
        return $this->imei;
    }

    public function setImei($imei)
    {
        $this->imei = $imei;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $policy;
        $this->policy = $phonePolicy;
        $this->setImei($phonePolicy->getImei());
    }
}
