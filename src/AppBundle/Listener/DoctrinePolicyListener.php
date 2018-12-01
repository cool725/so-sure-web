<?php

namespace AppBundle\Listener;

use AppBundle\Annotation\DataChange;
use AppBundle\Document\CurrencyTrait;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Policy;
use AppBundle\Event\PolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DoctrinePolicyListener extends BaseDoctrineListener
{
    use CurrencyTrait;

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        /** @var Policy $document */
        $document = $eventArgs->getDocument();
        if ($document instanceof Policy) {
            if (!$document->isValidPolicy()) {
                return;
            }
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['potValue', 'promoPotValue']
        )) {
            $this->triggerEvent($document, PolicyEvent::EVENT_UPDATED_POT);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['premium'],
            DataChange::COMPARE_OBJECT_EQUALS
        )) {
            $this->triggerEvent($document, PolicyEvent::EVENT_UPDATED_PREMIUM);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['billing'],
            DataChange::COMPARE_OBJECT_EQUALS
        )) {
            $this->triggerEvent($document, PolicyEvent::EVENT_UPDATED_BILLING);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            Policy::class,
            ['status'],
            DataChange::COMPARE_EQUAL,
            true
        )) {
            $this->triggerEvent($document, PolicyEvent::EVENT_UPDATED_STATUS, $eventArgs->getOldValue('status'));
        }
    }

    public function preRemove(LifecycleEventArgs $eventArgs)
    {
        /** @var Policy $document */
        $document = $eventArgs->getDocument();
        if ($document instanceof Policy) {
            if ($document->getStatus()) {
                throw new \Exception(sprintf('Unable to delete policy %s with status', $document->getId()));
            }
        }
    }

    private function triggerEvent(Policy $policy, $eventType, $previousStatus = null)
    {
        $event = new PolicyEvent($policy);
        if ($previousStatus) {
            $event->setPreviousStatus($previousStatus);
        }
        $this->dispatcher->dispatch($eventType, $event);
    }
}
