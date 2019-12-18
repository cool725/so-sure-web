<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Lead;

class LeadEvent extends Event
{
    const EVENT_UPDATED = 'event.lead.updated';
    const EVENT_CREATED = 'event.lead.created';

    /** @var Lead */
    protected $lead;

    public function __construct(Lead $lead)
    {
        $this->lead = $lead;
    }

    public function getLead()
    {
        return $this->lead;
    }
}
