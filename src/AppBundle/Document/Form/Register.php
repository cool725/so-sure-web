<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Register
{
    /**
     * @var string
     * @Assert\Email(strict=true)
     * @Assert\NotNull()
     */
    protected $email;

    /**
     * @var string
     * @AppAssert\UkMobile()
     * @Assert\NotNull()
     */
    protected $mobileNumber;

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }

    public function getMobileNumber()
    {
        return $this->mobileNumber;
    }

    public function setMobileNumber($mobileNumber)
    {
        $this->mobileNumber = $mobileNumber;
    }

    public function populateFromUser($user)
    {
        $this->setEmail($user->getEmail());
        $this->setMobileNumber($user->getMobileNumber());
    }

    public function populateUser($user)
    {
        $user->setEmail($this->getEmail());
        $user->setMobileNumber($this->getMobileNumber());
    }
}
