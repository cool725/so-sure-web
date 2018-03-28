<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Policy;
use AppBundle\Event\PolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DoctrinePolicyListener
{
    /** @var EventDispatcher */
    protected $dispatcher;

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof Policy) {
            if (!$document->isValidPolicy()) {
                return;
            }

            $fields = [
                'potValue',
                'promoPotValue',
            ];
            foreach ($fields as $field) {
                if ($eventArgs->hasChangedField($field)) {
                    return $this->triggerEvent($document, PolicyEvent::EVENT_UPDATED_POT);
                }
            }
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

    private function triggerEvent(Policy $policy, $eventType)
    {
        $event = new PolicyEvent($policy);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
