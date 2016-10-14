<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Policy;

class PolicyEvent extends Event
{
    const EVENT_CREATED = 'event.policy.created';
    const EVENT_CANCELLED = 'event.policy.cancelled';

    // Certain changes to a policy (user) should trigger a new salva version
    const EVENT_SALVA_INCREMENT = 'event.policy.salva_increment';

    const EVENT_UPDATED_POT = 'event.policy.pot';

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
