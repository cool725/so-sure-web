<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Policy;

class PolicyEvent extends Event
{
    const EVENT_CREATED = 'event.policy.created';
    const EVENT_UPDATED = 'event.policy.updated';
    const EVENT_CANCELLED = 'event.policy.cancelled';

    /** @var PhonePolicy */
    protected $policy;

    public function __construct(Policy $policy)
    {
        $this->policy = $policy;
    }

    public function getPolicy()
    {
        return $this->policy;
    }
}
