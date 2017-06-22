<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class PhoneMake
{
    /**
     * @var string
     * @Assert\NotBlank()
     */
    protected $make;

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

    public function getPhoneId()
    {
        return $this->phoneId;
    }

    public function setPhoneId($phoneId)
    {
        $this->phoneId = $phoneId;
    }
}
