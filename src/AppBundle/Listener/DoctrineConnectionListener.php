<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Connection\Connection;
use AppBundle\Event\ConnectionEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DoctrineConnectionListener extends BaseDoctrineListener
{
    /** @var EventDispatcher */
    protected $dispatcher;

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        if ($this->hasDataChanged(
            $eventArgs,
            StandardConnection::class,
            ['value', 'promoValue'],
            self::COMPARE_DECREASE
        )) {
            /** @var StandardConnection $document */
            $document = $eventArgs->getDocument();
            $this->triggerEvent($document);
        }
    }

    private function triggerEvent(Connection $connection)
    {
        $event = new ConnectionEvent($connection);
        $this->dispatcher->dispatch(ConnectionEvent::EVENT_REDUCED, $event);
    }
}
