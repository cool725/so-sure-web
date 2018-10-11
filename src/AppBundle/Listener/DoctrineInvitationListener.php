<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Event\InvitationEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DoctrineInvitationListener
{
    /** @var EventDispatcherInterface */
    protected $dispatcher;

    public function __construct($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function postPersist(LifecycleEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof Invitation) {
            $this->triggerEvent($document);
        }
    }

    public function postUpdate(LifecycleEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof Invitation) {
            $this->triggerEvent($document);
        }
    }

    private function triggerEvent(Invitation $invitation)
    {
        // only interested in invitation if there isn't an invitee already
        if ($invitation->getInvitee()) {
            return;
        }

        $event = new InvitationEvent($invitation);
        $this->dispatcher->dispatch(InvitationEvent::EVENT_UPDATED, $event);
    }
}
