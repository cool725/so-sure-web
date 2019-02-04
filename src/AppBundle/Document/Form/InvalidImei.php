<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use Symfony\Component\Validator\Constraints as Assert;

class InvalidImei
{
    /**
     * @var Policy
     * @Assert\NotNull(message="Policy is required.")
     */
    protected $policy;

    protected $note;

    protected $invalidImei;

    /**
     * @var Phone
     */
    protected $phone;

    public function hasInvalidImei()
    {
        return $this->invalidImei;
    }

    public function setInvalidImei($invalidImei)
    {
        $this->invalidImei = $invalidImei;
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
        $this->setInvalidImei($phonePolicy->hasInvalidImei());
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
