<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\PolicyEvent;
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
}
