<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\User;

class UserPaymentEvent extends Event
{
    const EVENT_FAILED = 'event.user.payment.failed';

    /** @var User */
    protected $user;

    protected $reason;
    
    public function __construct(User $user, $reason)
    {
        $this->user = $user;
        $this->reason = $reason;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function getReason()
    {
        return $this->reason;
    }
}
