<?php

namespace AppBundle\Document\Opt;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document()
 */
class EmailOptIn extends Opt
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
        if ($this->email != mb_strtolower($email)) {
            $this->setUpdated(\DateTime::createFromFormat('U', time()));
        }
        $this->email = mb_strtolower($email);
    }
}
