<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class PhoneDropdown
{
    /**
     * @var string
     * @Assert\NotBlank()
     */
    protected $make;

    /**
     * @var string
     */
    protected $model;

    /**
     * @var string
     */
    protected $memory;

    /**
     * @var string
     */
    protected $phoneId;

    public function getMake()
    {
        return $this->make;
    }

    public function setMake($make)
    {
        $this->make = $make;
    }

    public function setModel($model)
    {
        $this->model = $model;
    }

    public function getModel()
    {
        return $this->model;
    }

    public function setMemory($memory)
    {
        $this->memory = $memory;
    }

    public function getMemory()
    {
        return $this->memory;
    }

    public function setPhoneId($phoneId)
    {
        $this->phoneId = $phoneId;
    }

    public function getPhoneId()
    {
        return $this->phoneId;
    }
}
