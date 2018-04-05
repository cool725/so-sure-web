<?php

namespace AppBundle\Document\OptOut;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\OptOut\EmailOptOutRepository")
 */
class EmailOptOut extends OptOut
{
    /**
     * @Assert\Email(strict=true)
     * @MongoDB\Field(type="string", nullable=false)
     */
    protected $email;

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = mb_strtolower($email);
    }
}
