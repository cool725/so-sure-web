<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Invitation\Invitation;

class InvitationEvent extends Event
{
    const EVENT_UPDATED = 'event.invitation.updated';

    const EVENT_RECEIVED = 'event.invitation.received';
    const EVENT_ACCEPTED = 'event.invitation.accepted';
    const EVENT_REJECTED = 'event.invitation.rejected';
    const EVENT_CANCELLED = 'event.invitation.cancelled';
    const EVENT_INVITED = 'event.invitation.invited';
    const EVENT_REINVITED = 'event.invitation.reinvited';

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
