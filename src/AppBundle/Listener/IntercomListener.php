<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\InvitationEvent;
use AppBundle\Service\IntercomService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

class IntercomListener
{
    /** @var IntercomService */
    protected $intercom;

    /**
     * @param IntercomService $intercom
     */
    public function __construct(IntercomService $intercom)
    {
        $this->intercom = $intercom;
    }

    public function onUserUpdatedEvent(UserEvent $event)
    {
        $this->intercom->queue($event->getUser());
    }

    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        $this->intercom->queuePolicy($event->getPolicy(), IntercomService::QUEUE_EVENT_POLICY_CREATED);
    }

    public function onPolicyPotEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        // TODO: Trigger intercom event
    }

    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        $this->intercom->queuePolicy($event->getPolicy(), IntercomService::QUEUE_EVENT_POLICY_CANCELLED);
    }

    public function onInvitationReceivedEvent(InvitationEvent $event)
    {
        $this->intercom->queueInvitation($event->getInvitation(), IntercomService::QUEUE_EVENT_INVITATION_RECEIVED);
    }

    public function onInvitationAcceptedEvent(InvitationEvent $event)
    {
        $this->intercom->queueInvitation($event->getInvitation(), IntercomService::QUEUE_EVENT_INVITATION_ACCEPTED);
    }

    public function onInvitationRejectedEvent(InvitationEvent $event)
    {
        $this->intercom->queueInvitation($event->getInvitation(), IntercomService::QUEUE_EVENT_INVITATION_REJECTED);
    }

    public function onInvitationCancelledEvent(InvitationEvent $event)
    {
        $this->intercom->queueInvitation($event->getInvitation(), IntercomService::QUEUE_EVENT_INVITATION_CANCELLED);
    }

    public function onInvitationInvitedEvent(InvitationEvent $event)
    {
        $this->intercom->queueInvitation($event->getInvitation(), IntercomService::QUEUE_EVENT_INVITATION_INVITED);
    }

    public function onInvitationReinvitedEvent(InvitationEvent $event)
    {
        $this->intercom->queueInvitation($event->getInvitation(), IntercomService::QUEUE_EVENT_INVITATION_REINVITED);
    }
}
