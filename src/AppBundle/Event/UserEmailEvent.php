<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\User;

class UserEmailEvent extends Event
{
    const EVENT_CHANGED = 'event.user.email.changed';

    /** @var User */
    protected $user;

    /** @var string */
    protected $oldEmail;

    public function __construct(User $user, $oldEmail)
    {
        $this->user = $user;
        $this->oldEmail = $oldEmail;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function getOldEmail()
    {
        return $this->oldEmail;
    }
}
