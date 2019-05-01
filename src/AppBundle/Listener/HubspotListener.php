<?php

namespace AppBundle\Listener;

use AppBundle\Event\InvitationEvent;
use AppBundle\Event\PaymentEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserPaymentEvent;
use AppBundle\Service\HubspotService;

/**
 * Waits for events to occur in our system and then updates the state of Hubspot to match.
 */
class HubspotListener
{
    /** @var HubspotService */
    protected $hubspotService;

    /**
     * Builds the listener.
     * @param HubspotService $hubspotService provides hubspotService functionality.
     */
    public function __construct(HubspotService $hubspotService)
    {
        $this->hubspotService = $hubspotService;
    }

    /**
     * Hubspot actions for when a user is updated or created.
     * @param UserEvent $event is the event object representing the user update.
     */
    public function onUserUpdatedEvent(UserEvent $event)
    {
        $this->hubspotService->queueUpdateContact($event->getUser());
    }

    /**
     * Hubspot actions for when a policy changes or is created.
     * @param PolicyEvent $event represents the policy.
     */
    public function onPolicyUpdatedEvent(PolicyEvent $event)
    {
        $this->hubspotService->queueUpdateDeal($event->getPolicy());
    }
}
