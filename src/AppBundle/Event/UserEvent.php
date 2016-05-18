<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\User;

class UserEvent extends Event
{
    const EVENT_UPDATED = 'event.user.updated';
    const EVENT_EMAIL_VERIFIED = 'event.user.email_verified';

    /** @var User */
    protected $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser()
    {
        return $this->user;
    }
}
