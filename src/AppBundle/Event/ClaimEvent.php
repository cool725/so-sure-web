<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Claim;

class ClaimEvent extends Event
{
    const EVENT_CREATED = 'event.claim.created';
    const EVENT_APPROVED = 'event.claim.approved';
    const EVENT_SETTLED = 'event.claim.settled';

    /** @var Claim */
    protected $claim;

    public function __construct(Claim $claim)
    {
        $this->claim = $claim;
    }

    public function getClaim()
    {
        return $this->claim;
    }
}
