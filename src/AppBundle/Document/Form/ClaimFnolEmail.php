<?php

namespace AppBundle\Document\Form;

use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class ClaimFnolEmail
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

/*
    public function populateFromUser($user)
    {
        $this->setEmail($user->getEmail());
    }

    public function populateUser($user)
    {
        $user->setEmail($this->getEmail());
    }
    */
}