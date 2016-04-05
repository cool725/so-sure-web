<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Invitation\Invitation;

class InvitationEvent extends Event
{
    const EVENT_UPDATED = 'event.invitation.updated';

    /** @var Invitation */
    protected $invitation;

    public function __construct(Invitation $invitation)
    {
        $this->invitation = $invitation;
    }

    public function getInvitation()
    {
        return $this->invitation;
    }
}