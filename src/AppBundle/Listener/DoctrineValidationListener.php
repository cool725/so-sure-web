<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\ObjectEvent;

class DoctrineValidationListener
{
    /** @var EventDispatcher */
    protected $dispatcher;

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function prePersist(LifecycleEventArgs $eventArgs)
    {
        $this->triggerEvent($eventArgs->getDocument());
    }
    
    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        $this->triggerEvent($eventArgs->getDocument());
    }

    private function triggerEvent($object)
    {
        $event = new ObjectEvent($object);
        $this->dispatcher->dispatch(ObjectEvent::EVENT_VALIDATE, $event);
    }
}
