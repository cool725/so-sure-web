<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

class DoctrineUserListener
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
        if ($document instanceof User) {
            $this->triggerEvent($document);
        }
    }

    public function postUpdate(LifecycleEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof User) {
            $this->triggerEvent($document);
        }
    }

    private function triggerEvent(User $user)
    {
        $event = new UserEvent($user);
        $this->dispatcher->dispatch(UserEvent::EVENT_UPDATED, $event);
    }
}
