<?php

namespace AppBundle\Event;

use AppBundle\Document\BankAccount;
use AppBundle\Document\User;
use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Claim;

class CardEvent extends Event
{
    const EVENT_UPDATED = 'event.card.updated';

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
