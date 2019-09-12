<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\Invitation\EmailInvitationRepository")
 */
class EmailInvitation extends Invitation
{
    /**
     * @Assert\Email(strict=true)
     * @MongoDB\Field(type="string", nullable=false)
     */
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

    public function getChannelDetails()
    {
        return null;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = mb_strtolower($email);
    }

    /**
     * @InheritDoc
     */
    public function getSharerPolicy()
    {
        return $this->getInviterPolicy();
    }
}
