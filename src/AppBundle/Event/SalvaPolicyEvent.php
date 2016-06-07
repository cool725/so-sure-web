<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\PhonePolicy;

class SalvaPolicyEvent extends Event
{
    const EVENT_UPDATED = 'event.salva.updated';

    /** @var PhonePolicy */
    protected $policy;

    public function __construct(PhonePolicy $policy)
    {
        $this->policy = $policy;
    }

    public function getPhonePolicy()
    {
        return $this->policy;
    }
}
