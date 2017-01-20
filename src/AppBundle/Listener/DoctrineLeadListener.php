<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Lead;
use AppBundle\Event\LeadEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DoctrineLeadListener
{
    /** @var EventDispatcher */
    protected $dispatcher;

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function postPersist(LifecycleEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof Lead) {
            $this->triggerEvent($document, LeadEvent::EVENT_UPDATED);
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
