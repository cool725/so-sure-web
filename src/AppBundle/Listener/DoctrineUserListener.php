<?php

namespace AppBundle\Listener;

use AppBundle\Annotation\DataChange;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Charge;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\CardEvent;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserEmailEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DoctrineUserListener extends BaseDoctrineListener
{
    /** @var EventDispatcherInterface */
    protected $dispatcher;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct($dispatcher = null, LoggerInterface $logger = null)
    {
        $this->dispatcher = $dispatcher;
        if ($logger) {
            $this->logger = $logger;
        }
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
        /** @var User $user */
        $user = $eventArgs->getDocument();

        if ($this->hasDataChanged(
            $eventArgs,
            User::class,
            ['email'],
            DataChange::COMPARE_CASE_INSENSITIVE,
            true
        )) {
            $event = new UserEmailEvent($user, $eventArgs->getOldValue('email'));
            $this->dispatcher->dispatch(UserEmailEvent::EVENT_CHANGED, $event);
        }

        // If both confirmationToken & passwordRequestAt are changing to null,
        // then the user has reset their password using their token.
        // This was most likely received by email and if so, then their email should be valid
        // TODO: Figure out how to handle a manual process
        if ($this->hasDataChanged($eventArgs, User::class, ['confirmationToken'], DataChange::COMPARE_TO_NULL) &&
            $this->hasDataChanged($eventArgs, User::class, ['passwordRequestedAt'], DataChange::COMPARE_TO_NULL)) {
            $user->setEmailVerified(true);

            // Email Verified probably isn't in the original changeset, so recalculate
            $this->recalulateChangeSet($eventArgs, $user);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            User::class,
            ['password'],
            DataChange::COMPARE_CASE_INSENSITIVE,
            true
        )) {
            if ($eventArgs->hasChangedField('salt')) {
                $user->passwordChange($eventArgs->getOldValue('password'), $eventArgs->getOldValue('salt'));
            } else {
                $user->passwordChange($eventArgs->getOldValue('password'), $user->getSalt());
            }

            // previousPasswords probably isn't in the original changeset, so recalculate
            $this->recalulateChangeSet($eventArgs, $user);

            $this->triggerEvent($user, UserEvent::EVENT_PASSWORD_CHANGED);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            User::class,
            ['firstName', 'lastName'],
            DataChange::COMPARE_EQUAL,
            true
        )) {
            $this->triggerEvent($user, UserEvent::EVENT_NAME_UPDATED);
        }

        if (
            $this->hasDataChangedByCategory($eventArgs, DataChange::CATEGORY_HUBSPOT, User::class) &&
            $user->getHubspotId()
        ) {
            $this->triggerEvent($user, UserEvent::EVENT_UPDATED_HUBSPOT);
        }

        if ($this->hasDataChangedByCategory($eventArgs, DataChange::CATEGORY_INTERCOM)) {
            $this->triggerEvent($user, UserEvent::EVENT_UPDATED_INTERCOM);
        }

        if ($this->hasDataChangedByCategory($eventArgs, DataChange::CATEGORY_INVITATION_LINK)) {
            $this->triggerEvent($user, UserEvent::EVENT_UPDATED_INVITATION_LINK);
        }
    }

    public function preRemove(LifecycleEventArgs $eventArgs)
    {
        /** @var User $document */
        $document = $eventArgs->getDocument();
        if (!$document instanceof User) {
            return;
        }

        /** @var User $user */
        $user = $document;

        if (count($user->getCreatedPolicies()) > 0) {
            throw new \Exception(sprintf(
                'Unable to delete user %s w/non-partial policy',
                $user->getId()
            ));
        }

        foreach ($user->getSentInvitations() as $sentInvitation) {
            /** @var Invitation $sentInvitation */
            $sentInvitation->setInviter(null);
        }

        foreach ($user->getCharges() as $charge) {
            /** @var Charge $charge */
            $charge->setUser(null);
        }
    }

    private function triggerEvent(User $user, $eventType)
    {
        $event = new UserEvent($user);
        $this->dispatcher->dispatch($eventType, $event);
    }

    private function triggerCardEvent(User $user, $eventType)
    {
        $event = new CardEvent();
        $event->setUser($user);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
