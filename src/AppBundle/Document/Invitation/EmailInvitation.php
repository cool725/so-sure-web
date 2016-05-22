<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\Invitation\EmailInvitationRepository")
 */
class EmailInvitation extends Invitation
{
    /** @MongoDB\Field(type="string") */
    protected $email;

    public function isSingleUse()
    {
        return true;
    }

    public function getChannel()
    {
        return 'email';
    }

    public function getMaxReinvitations()
    {
        return 5;
    }

    public function getInvitationDetail()
    {
        return $this->getEmail();
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = strtolower($email);
    }
}
