<?php

namespace AppBundle\Listener;

use AppBundle\Document\BankAccount;
use AppBundle\Event\BacsEvent;
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

            $fields = [
                'firstName',
                'lastName',
            ];
            foreach ($fields as $field) {
                if ($eventArgs->hasChangedField($field) && strlen(trim($eventArgs->getOldValue($field))) > 0 &&
                    $eventArgs->getOldValue($field) != $eventArgs->getNewValue($field)) {
                    $event = new UserEvent($document);
                    $this->dispatcher->dispatch(UserEvent::EVENT_NAME_UPDATED, $event);
                }
            }

            $fields = [
                'paymentMethod.bankAccount.sortCode',
                'paymentMethod.bankAccount.accountNumber',
            ];
            foreach ($fields as $field) {
                if ($eventArgs->hasChangedField($field) && strlen(trim($eventArgs->getOldValue($field))) > 0 &&
                    $eventArgs->getOldValue($field) != $eventArgs->getNewValue($field)) {
                    $accountName = $eventArgs->hasChangedField('paymentMethod.bankAccount.accountName') ?
                        $eventArgs->getOldValue('paymentMethod.bankAccount.accountName') :
                        $document->getPaymentMethod()->getBankAccount()->getAccountName();
                    $sortCode = $eventArgs->hasChangedField('paymentMethod.bankAccount.sortCode') ?
                        $eventArgs->getOldValue('paymentMethod.bankAccount.sortCode') :
                        $document->getPaymentMethod()->getBankAccount()->getSortCode();
                    $accountNumber = $eventArgs->hasChangedField('paymentMethod.bankAccount.accountNumber') ?
                        $eventArgs->getOldValue('paymentMethod.bankAccount.accountNumber') :
                        $document->getPaymentMethod()->getBankAccount()->getAccountNumber();
                    $reference = $eventArgs->hasChangedField('paymentMethod.bankAccount.reference') ?
                        $eventArgs->getOldValue('paymentMethod.bankAccount.reference') :
                        $document->getPaymentMethod()->getBankAccount()->getReference();
                    $bankAccount = BankAccount::create(
                        $accountName,
                        $sortCode,
                        $accountNumber,
                        $reference
                    );
                    $event = new BacsEvent($bankAccount, $document->getId());
                    $this->dispatcher->dispatch(BacsEvent::EVENT_UPDATED, $event);
                }
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
