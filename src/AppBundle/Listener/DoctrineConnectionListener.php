<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Connection\Connection;
use AppBundle\Event\ConnectionEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DoctrineConnectionListener
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
        if ($document instanceof StandardConnection) {
            if ($eventArgs->hasChangedField('value') && $eventArgs->getOldValue('value') > 0 &&
                $eventArgs->getOldValue('value') > $eventArgs->getNewValue('value')) {
                $this->triggerEvent($document);
            } elseif ($eventArgs->hasChangedField('promoValue')  && $eventArgs->getOldValue('promoValue') > 0 &&
                $eventArgs->getOldValue('promoValue') > $eventArgs->getNewValue('promoValue')) {
                $this->triggerEvent($document);
            }
        }
    }

    private function triggerEvent(Connection $connection)
    {
        $event = new ConnectionEvent($connection);
        $this->dispatcher->dispatch(ConnectionEvent::EVENT_REDUCED, $event);
    }
}
