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
     * @Assert\NotBlank()
     */
    protected $email;

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }

    public function populateFromUser($user)
    {
        $this->setEmail($user->getEmail());
    }

    public function populateUser($user)
    {
        $user->setEmail($this->getEmail());
    }
}
