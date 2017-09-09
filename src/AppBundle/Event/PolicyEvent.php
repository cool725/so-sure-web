<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Policy;

class PolicyEvent extends Event
{
    const EVENT_CREATED = 'event.policy.created';
    const EVENT_CANCELLED = 'event.policy.cancelled';
    const EVENT_EXPIRED = 'event.policy.expired';
    const EVENT_PENDING_RENEWAL = 'event.policy.pending-renewal';
    const EVENT_RENEWED = 'event.policy.renewed';
    const EVENT_START = 'event.policy.start';

    // Certain changes to a policy (user) should trigger a new salva version
    const EVENT_SALVA_INCREMENT = 'event.policy.salva_increment';

    const EVENT_UPDATED_POT = 'event.policy.pot';

    /** @var PhonePolicy */
    protected $policy;
    protected $date;

    public function __construct(Policy $policy, \DateTime $date = null)
    {
        $this->policy = $policy;
        if (!$date) {
            $date = new \DateTime();
        }
        $this->date = $date;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function getDate()
    {
        return $this->date;
    }
}
