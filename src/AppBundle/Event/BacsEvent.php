<?php

namespace AppBundle\Event;

use AppBundle\Document\BankAccount;
use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Claim;

class BacsEvent extends Event
{
    const EVENT_UPDATED = 'event.bacs.updated';

    /** @var BankAccount */
    protected $bankAccount;

    public function __construct(BankAccount $bankAccount)
    {
        $this->bankAccount = $bankAccount;
    }

    public function getBankAccount()
    {
        return $this->bankAccount;
    }
}
