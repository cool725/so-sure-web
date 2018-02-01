<?php

namespace AppBundle\Listener;

use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserEmailEvent;
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
            $this->triggerEvent($document, UserEvent::EVENT_CREATED);
        }
    }

    public function preUpdate(PreUpdateEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof User) {
            if ($eventArgs->hasChangedField('email') &&
                strlen(trim($eventArgs->getOldValue('email'))) > 0 &&
                strtolower($eventArgs->getOldValue('email')) != strtolower($eventArgs->getNewValue('email'))) {
                $event = new UserEmailEvent($document, $eventArgs->getOldValue('email'));
                $this->dispatcher->dispatch(UserEmailEvent::EVENT_CHANGED, $event);
            }

            // If both confirmationToken & passwordRequestAt are changing to null,
            // then the user has reset their password using their token.
            // This was most likely received by email and if so, then their email should be valid
            // TODO: Figure out how to handle a manual process
            if ($eventArgs->hasChangedField('confirmationToken') &&
                $eventArgs->getNewValue('confirmationToken') == null &&
                $eventArgs->hasChangedField('passwordRequestedAt') &&
                $eventArgs->getNewValue('passwordRequestedAt') == null) {
                $document->setEmailVerified(true);

                // Email Verified probably isn't in the original changeset, so recalculate
                $dm = $eventArgs->getDocumentManager();
                $uow = $dm->getUnitOfWork();
                $meta = $dm->getClassMetadata(get_class($document));
                $uow->recomputeSingleDocumentChangeSet($meta, $document);
            }

            if ($eventArgs->hasChangedField('password') &&
                strlen(trim($eventArgs->getOldValue('password'))) > 0 &&
                $eventArgs->getOldValue('password') != $eventArgs->getNewValue('password')) {
                if ($eventArgs->hasChangedField('salt')) {
                    $document->passwordChange($eventArgs->getOldValue('password'), $eventArgs->getOldValue('salt'));
                } else {
                    $document->passwordChange($eventArgs->getOldValue('password'), $document->getSalt());
                }

                // previousPasswords probably isn't in the original changeset, so recalculate
                $dm = $eventArgs->getDocumentManager();
                $uow = $dm->getUnitOfWork();
                $meta = $dm->getClassMetadata(get_class($document));
                $uow->recomputeSingleDocumentChangeSet($meta, $document);

                $event = new UserEvent($document);
                $this->dispatcher->dispatch(UserEvent::EVENT_PASSWORD_CHANGED, $event);
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
