<?php

namespace AppBundle\Listener;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Event\BacsEvent;
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

    public function __construct(EventDispatcherInterface $dispatcher, LoggerInterface $logger)
    {
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
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
            self::COMPARE_CASE_INSENSITIVE
        )) {
            $event = new UserEmailEvent($user, $eventArgs->getOldValue('email'));
            $this->dispatcher->dispatch(UserEmailEvent::EVENT_CHANGED, $event);
        }

        // If both confirmationToken & passwordRequestAt are changing to null,
        // then the user has reset their password using their token.
        // This was most likely received by email and if so, then their email should be valid
        // TODO: Figure out how to handle a manual process
        if ($this->hasDataChanged($eventArgs, User::class, ['confirmationToken'], self::COMPARE_TO_NULL) &&
            $this->hasDataChanged($eventArgs, User::class, ['passwordRequestedAt'], self::COMPARE_TO_NULL)) {
            $user->setEmailVerified(true);

            // Email Verified probably isn't in the original changeset, so recalculate
            $this->recalulateChangeSet($eventArgs, $user);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            User::class,
            ['password'],
            self::COMPARE_CASE_INSENSITIVE,
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
            self::COMPARE_EQUAL,
            true
        )) {
            $this->triggerEvent($user, UserEvent::EVENT_NAME_UPDATED);
        }

        if ($this->hasDataChanged(
            $eventArgs,
            User::class,
            ['paymentMethod'],
            self::COMPARE_BACS
        )) {
            /** @var BacsPaymentMethod $paymentMethod */
            $paymentMethod = $user->getPaymentMethod();

            // prefer the old bank account data if it exists
            $oldValue = $eventArgs->getOldValue();
            if ($oldValue instanceof BacsPaymentMethod && $oldValue->getBankAccount()) {
                /** @var BacsPaymentMethod $paymentMethod */
                $paymentMethod = $oldValue->getPaymentMethod();
            }

            $bankAccount = clone $paymentMethod->getBankAccount();
            $event = new BacsEvent($bankAccount, $user->getId());
            $this->dispatcher->dispatch(BacsEvent::EVENT_UPDATED, $event);
        }
    }

    public function postUpdate(LifecycleEventArgs $eventArgs)
    {
        $document = $eventArgs->getDocument();
        if ($document instanceof User) {
            $this->triggerEvent($document, UserEvent::EVENT_UPDATED);
        }
    }

    public function preRemove(LifecycleEventArgs $eventArgs)
    {
        /** @var User $document */
        $document = $eventArgs->getDocument();
        if ($document instanceof User) {
            if (count($document->getCreatedPolicies()) > 0) {
                throw new \Exception(sprintf(
                    'Unable to delete user %s w/non-partial policy',
                    $document->getId()
                ));
            }
        }
    }

    private function triggerEvent(User $user, $eventType)
    {
        $event = new UserEvent($user);
        $this->dispatcher->dispatch($eventType, $event);
    }
}
