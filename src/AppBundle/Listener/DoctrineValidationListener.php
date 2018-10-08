<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\ObjectEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DoctrineValidationListener
{
    /** @var EventDispatcherInterface */
    protected $dispatcher;

    public function __construct(EventDispatcherInterface $dispatcher)
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
