<?php

namespace AppBundle\Listener;

use AppBundle\Annotation\DataChange;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Interfaces\EqualsInterface;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Symfony\Component\PropertyAccess\PropertyAccess;

class BaseDoctrineListener
{
    use CurrencyTrait;

    /** @var Reader */
    protected $reader;

    public function setReader(Reader $reader)
    {
        $this->reader = $reader;
    }

    const COMPARE_EQUAL = 'equal';
    const COMPARE_CASE_INSENSITIVE = 'case-insensitive';
    const COMPARE_INCREASE = 'increase';
    const COMPARE_DECREASE = 'decrease';
    const COMPARE_OBJECT_EQUALS = 'object-equals';
    const COMPARE_OBJECT_SERIALIZE = 'object-serialize';
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
            if ($this->hasDataFieldChanged($eventArgs, $class, $field, $compare, $mustExist)) {
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

        if ($eventArgs->hasChangedField($field)) {
            $oldValue = $eventArgs->getOldValue($field);
            $newValue = $eventArgs->getNewValue($field);

            if ($mustExist && mb_strlen(trim($oldValue)) == 0) {
                return false;
            }

            if ($compare == self::COMPARE_EQUAL) {
                return $oldValue !== $newValue;
            } elseif ($compare == self::COMPARE_CASE_INSENSITIVE) {
                return mb_strtolower($oldValue) !== mb_strtolower($newValue);
            } elseif ($compare == self::COMPARE_OBJECT_SERIALIZE) {
                return serialize($oldValue) == serialize($newValue);
            } elseif ($compare == self::COMPARE_OBJECT_EQUALS) {
                if (!$oldValue && !$newValue) {
                    return false;
                } elseif (!$oldValue && $newValue) {
                    return true;
                } elseif ($oldValue instanceof EqualsInterface) {
                    return !$oldValue->equals($newValue);
                } else {
                    return null;
                }
            } elseif ($compare == self::COMPARE_INCREASE) {
                return $oldValue < $newValue;
            } elseif ($compare == self::COMPARE_DECREASE) {
                return $oldValue > $newValue;
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

    protected function hasDataChangedByCategory(PreUpdateEventArgs $eventArgs, $category)
    {
        $document = $eventArgs->getDocument();
        $annotations = $this->getDataChangeAnnotation($document, $category);
        foreach ($annotations as $property => $value) {
            if ($this->hasDataFieldChanged($eventArgs, get_class($document), $property)) {
                return true;
            }
        }

        return false;
    }

    private function getDataChangeAnnotation($object, $category)
    {
        if (!$this->reader) {
            throw new \Exception('Missing annotation reader');
        }

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $items = [];
        // Get method annotation
        $reflectionObject = new \ReflectionObject($object);
        $properties = $reflectionObject->getProperties();
        foreach ($properties as $property) {
            /** @var \ReflectionProperty $property */
            /** @var DataChange $propertyAnnotation */
            $propertyAnnotation = $this->reader->getPropertyAnnotation($property, DataChange::class);
            if ($propertyAnnotation && in_array($category, $propertyAnnotation->getCategories())) {
                $items[$property->getName()] = $propertyAccessor->getValue($object, $property->getName());
            }
        }

        return $items;
    }
}
