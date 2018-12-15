<?php

namespace AppBundle\Event;

use AppBundle\Document\BankAccount;
use AppBundle\Document\User;
use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Claim;

class BacsEvent extends Event
{
    const EVENT_UPDATED = 'event.bacs.updated';

    /** @var User */
    protected $user;

    /** @var BankAccount */
    protected $bankAccount;

    public function __construct(User $user, BankAccount $bankAccount)
    {
        $this->user = $user;
        $this->bankAccount = $bankAccount;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function getBankAccount()
    {
        return $this->bankAccount;
    }
}
