<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Lead;
use AppBundle\Event\LeadEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DoctrineLeadListener
{
    /** @var EventDispatcherInterface */
    protected $dispatcher;

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function postPersist(LifecycleEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof Lead) {
            $this->triggerEvent($document, LeadEvent::EVENT_CREATED);
        }
    }

    public function postUpdate(LifecycleEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof Lead) {
            $this->triggerEvent($document, LeadEvent::EVENT_UPDATED);
        }
    }

    private function triggerEvent(Lead $lead, $eventType)
    {
        $event = new LeadEvent($lead);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
