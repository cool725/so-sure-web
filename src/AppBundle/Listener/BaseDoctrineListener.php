<?php

namespace AppBundle\Listener;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\CurrencyTrait;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;

class BaseDoctrineListener
{
    use CurrencyTrait;

    const COMPARE_EQUAL = 'equal';
    const COMPARE_CASE_INSENSITIVE = 'case-insensitive';
    const COMPARE_INCREASE = 'increase';
    const COMPARE_DECREASE = 'decrease';
    const COMPARE_PREMIUM = 'premium';
    const COMPARE_TO_NULL = 'to-null';
    const COMPARE_BACS = 'bacs';

    protected function recalulateChangeSet(PreUpdateEventArgs $eventArgs, $updatedDocument)
    {
        $dm = $eventArgs->getDocumentManager();
        $uow = $dm->getUnitOfWork();
        $meta = $dm->getClassMetadata(get_class($updatedDocument));
        $uow->recomputeSingleDocumentChangeSet($meta, $updatedDocument);
    }

    protected function hasDataChanged(
        PreUpdateEventArgs $eventArgs,
        $class,
        $fields,
        $compare = self::COMPARE_EQUAL,
        $mustExist = false
    ) {
        foreach ($fields as $field) {
            if ($this->hasDataChanged($eventArgs, $class, $field, $compare, $mustExist)) {
                return true;
            }
        }

        return false;
    }

    private function hasDataFieldChanged(
        PreUpdateEventArgs $eventArgs,
        $class,
        $field,
        $compare = self::COMPARE_EQUAL,
        $mustExist = false
    ) {
        $document = $eventArgs->getDocument();
        if (!$document instanceof $class) {
            return null;
        }

        $oldValue = $eventArgs->getOldValue($field);
        $newValue = $eventArgs->getNewValue($field);

        if ($eventArgs->hasChangedField($field)) {
            if ($mustExist && mb_strlen(trim($oldValue)) == 0) {
                return false;
            }

            if ($compare == self::COMPARE_EQUAL) {
                return $oldValue == $newValue;
            } elseif ($compare == self::COMPARE_CASE_INSENSITIVE) {
                return mb_strtolower($oldValue) == mb_strtolower($newValue);
            } elseif ($compare == self::COMPARE_INCREASE) {
                return $oldValue < $newValue;
            } elseif ($compare == self::COMPARE_DECREASE) {
                return $oldValue > $newValue;
            } elseif ($compare == self::COMPARE_PREMIUM) {
                if (!$oldValue && !$newValue) {
                    return false;
                } elseif (!$oldValue && $newValue) {
                    return true;
                } elseif (!$this->areEqualToTwoDp($oldValue->getGwp(), $newValue->getGwp())) {
                    return true;
                } elseif (!$this->areEqualToTwoDp($oldValue->getIpt(), $newValue->getIpt())) {
                    return true;
                } elseif (!$this->areEqualToTwoDp($oldValue->getIptRate(), $newValue->getIptRate())) {
                    return true;
                }

                return false;
            } elseif ($compare == self::COMPARE_TO_NULL) {
                return $newValue === null;
            } elseif ($compare == self::COMPARE_BACS) {
                if (!$oldValue instanceof BacsPaymentMethod || !$newValue instanceof BacsPaymentMethod) {
                    return false;
                }

                /** @var BankAccount $oldBankAccount */
                $oldBankAccount = $oldValue->getBankAccount();
                /** @var BankAccount $newBankAccount */
                $newBankAccount = $newValue->getBankAccount();

                if ($oldBankAccount->getAccountNumber() != $newBankAccount->getAccountNumber()) {
                    return true;
                } elseif ($oldBankAccount->getSortCode() != $newBankAccount->getSortCode()) {
                    return true;
                } elseif ($oldBankAccount->getAccountName() != $newBankAccount->getAccountName()) {
                    return true;
                } elseif ($oldBankAccount->getReference() != $newBankAccount->getReference()) {
                    return true;
                }

                return false;
            } else {
                throw new \Exception(sprintf('Unknown comparision %s', $compare));
            }
        }

        return false;
    }
}
