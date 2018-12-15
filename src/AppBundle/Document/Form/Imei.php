<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
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

    /**
     * @var Phone
     */
    protected $phone;

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
        $this->setPhone($phonePolicy->getPhone());
    }

    public function getNote()
    {
        return $this->note;
    }

    public function setNote($note)
    {
        $this->note = $note;
    }

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone($phone)
    {
        $this->phone = $phone;
    }
}
