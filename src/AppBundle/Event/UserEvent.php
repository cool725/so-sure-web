<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\User;

class UserEvent extends Event
{
    const EVENT_CREATED = 'event.user.created';
    const EVENT_UPDATED = 'event.user.updated';
    const EVENT_NAME_UPDATED = 'event.user.name.updated';
    const EVENT_PASSWORD_CHANGED = 'event.user.password.changed';

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
