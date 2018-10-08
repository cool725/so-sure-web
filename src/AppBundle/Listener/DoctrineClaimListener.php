<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Claim;
use AppBundle\Event\ClaimEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DoctrineClaimListener extends BaseDoctrineListener
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
        if ($document instanceof Claim) {
            $this->triggerEvent($document, ClaimEvent::EVENT_CREATED);

            if ($eventType = $this->getEventType($document->getStatus())) {
                $this->triggerEvent($document, $eventType);
            }
        }
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($this->hasDataChanged($eventArgs, Claim::class, ['status'])) {
            if ($eventType = $this->getEventType($eventArgs->getNewValue('status'))) {
                $this->triggerEvent($document, $eventType);
            }
            $this->triggerEvent($document, $eventArgs->getNewValue('status'));
        }
    }

    private function getEventType($status)
    {
        if ($status == Claim::STATUS_APPROVED) {
            return ClaimEvent::EVENT_APPROVED;
        } elseif ($status == Claim::STATUS_SETTLED) {
            return ClaimEvent::EVENT_SETTLED;
        }

        return null;
    }

    private function triggerEvent(Claim $claim, $eventType)
    {
        $event = new ClaimEvent($claim);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
