<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use Symfony\Component\Validator\Constraints as Assert;

class SerialNumber
{
    /**
     * @var Policy
     * @Assert\NotNull(message="Policy is required.")
     */
    protected $policy;

    protected $note;

    protected $serialNumber;

    public function getSerialNumber()
    {
        return $this->serialNumber;
    }

    public function setSerialNumber($serialNumber)
    {
        $this->serialNumber = $serialNumber;
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
        $this->setSerialNumber($phonePolicy->getSerialNumber());
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
