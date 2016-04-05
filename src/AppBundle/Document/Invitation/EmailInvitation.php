<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class EmailInvitation extends Invitation
{
    const EVENT_UPDATED = 'event.invitation.updated';

    /** @MongoDB\Field(type="string", nullable=false) */
    protected $email;

    public function isSingleUse()
    {
        return true;
    }
    
    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }
}
