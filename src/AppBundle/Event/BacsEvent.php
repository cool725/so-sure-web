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

    /** @var string */
    protected $id;

    public function __construct(BankAccount $bankAccount, $id)
    {
        $this->bankAccount = $bankAccount;
        $this->id = $id;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getBankAccount()
    {
        return $this->bankAccount;
    }
}
