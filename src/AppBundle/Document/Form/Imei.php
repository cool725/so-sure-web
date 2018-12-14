<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use Symfony\Component\Validator\Constraints as Assert;

class Imei
{
    /**
     * @var Policy
     * @Assert\NotNull(message="Policy is required.")
     */
    protected $policy;

    protected $note;

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

    public function getNote()
    {
        return $this->note;
    }

    public function setNote($note)
    {
        $this->note = $note;
    }
}
