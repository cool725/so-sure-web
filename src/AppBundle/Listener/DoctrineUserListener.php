<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
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
            $this->triggerEvent($document, UserEvent::EVENT_UPDATED);
        }
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof User) {
            // If both confirmationToken & passwordRequestAt are changing to null,
            // then the user has reset their password using their token.
            // This was most likely received by email and if so, then their email should be valid
            // TODO: Figure out how to handle a manual process
            if ($eventArgs->hasChangedField('confirmationToken') &&
                $eventArgs->getNewValue('confirmationToken') == null &&
                $eventArgs->hasChangedField('passwordRequestedAt') &&
                $eventArgs->getNewValue('passwordRequestedAt') == null) {
                $this->triggerEvent($document, UserEvent::EVENT_EMAIL_VERIFIED);
            }
        }
    }

    public function postUpdate(LifecycleEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof User) {
            $this->triggerEvent($document, UserEvent::EVENT_UPDATED);
        }
    }

    private function triggerEvent(User $user, $eventType)
    {
        $event = new UserEvent($user);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
