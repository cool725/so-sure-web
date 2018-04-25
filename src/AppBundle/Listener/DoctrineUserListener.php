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

class DoctrineUserListener
{
    /** @var EventDispatcher */
    protected $dispatcher;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct($dispatcher, LoggerInterface $logger)
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
        $document = $eventArgs->getDocument();
        if ($document instanceof User) {
            if ($eventArgs->hasChangedField('email') &&
                mb_strlen(trim($eventArgs->getOldValue('email'))) > 0 &&
                mb_strtolower($eventArgs->getOldValue('email')) != mb_strtolower($eventArgs->getNewValue('email'))) {
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
                mb_strlen(trim($eventArgs->getOldValue('password'))) > 0 &&
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
                if ($eventArgs->hasChangedField($field) && mb_strlen(trim($eventArgs->getOldValue($field))) > 0 &&
                    $eventArgs->getOldValue($field) != $eventArgs->getNewValue($field)) {
                    $event = new UserEvent($document);
                    $this->dispatcher->dispatch(UserEvent::EVENT_NAME_UPDATED, $event);
                }
            }

            if ($eventArgs->hasChangedField('paymentMethod')) {
                /** @var BankAccount $oldBankAccount */
                $oldBankAccount = $eventArgs->getOldValue('paymentMethod') &&
                    $eventArgs->getOldValue('paymentMethod') instanceof BacsPaymentMethod ?
                    $eventArgs->getOldValue('paymentMethod')->getBankAccount() :
                    null;
                /** @var BankAccount $newBankAccount */
                $newBankAccount = $eventArgs->getNewValue('paymentMethod') &&
                    $eventArgs->getNewValue('paymentMethod') instanceof BacsPaymentMethod ?
                    $eventArgs->getNewValue('paymentMethod')->getBankAccount() :
                    null;
                if ($oldBankAccount && $newBankAccount) {
                    $bankAccountUpdated = false;

                    /** @var BacsPaymentMethod $paymentMethod */
                    $paymentMethod = $document->getPaymentMethod();
                    $accountName = $paymentMethod->getBankAccount()->getAccountName();
                    $accountNumber = $paymentMethod->getBankAccount()->getAccountNumber();
                    $sortCode = $paymentMethod->getBankAccount()->getSortCode();
                    $reference = $paymentMethod->getBankAccount()->getReference();
                    if ($oldBankAccount->getAccountNumber() != $newBankAccount->getAccountNumber()) {
                        $accountNumber = $oldBankAccount->getAccountNumber();
                        $bankAccountUpdated = true;
                    }
                    if ($oldBankAccount->getSortCode() != $newBankAccount->getSortCode()) {
                        $sortCode = $oldBankAccount->getSortCode();
                        $bankAccountUpdated = true;
                    }
                    if ($oldBankAccount->getAccountName() != $newBankAccount->getAccountName()) {
                        $accountName = $oldBankAccount->getAccountName();
                        $bankAccountUpdated = true;
                    }
                    if ($oldBankAccount->getReference() != $newBankAccount->getReference()) {
                        $reference = $oldBankAccount->getReference();
                        $bankAccountUpdated = true;
                    }

                    if ($bankAccountUpdated) {
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
