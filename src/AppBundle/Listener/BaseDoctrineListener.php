<?php

namespace AppBundle\Listener;

use AppBundle\Annotation\DataChange;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Interfaces\EqualsInterface;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Psr\Log\LoggerInterface;

class BaseDoctrineListener
{
    use CurrencyTrait;

    /** @var Reader */
    protected $reader;

    /** @var LoggerInterface */
    protected $logger;

    public function setReader(Reader $reader)
    {
        $this->reader = $reader;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

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
        $compare = DataChange::COMPARE_EQUAL,
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
        $compare = DataChange::COMPARE_EQUAL,
        $mustExist = false
    ) {
        $document = $eventArgs->getDocument();
        if (!$document instanceof $class) {
            return null;
        }

        if ($eventArgs->hasChangedField($field)) {
            $oldValue = $eventArgs->getOldValue($field);
            $newValue = $eventArgs->getNewValue($field);

            /*
            if ($this->logger) {
                $this->logger->debug(sprintf(
                    'Changed field %s from %s to %s',
                    $field,
                    json_encode($oldValue),
                    json_encode($newValue)
                ));
            }
            */

            if ($mustExist && mb_strlen(trim($oldValue)) == 0) {
                return false;
            }

            if ($compare == DataChange::COMPARE_EQUAL) {
                if (is_float($oldValue)) {
                    $result = !$this->areEqualToSixDp($oldValue, $newValue);
                    /*
                    if ($this->logger) {
                        $this->logger->debug(sprintf(
                            'Return %s for compare %s (float)',
                            $result ? 'true' : 'false',
                            $compare
                        ));
                    }
                    */
                    return $result;
                }
                if ($oldValue === null && is_string($newValue) && mb_strlen($newValue) == 0) {
                    $result = false;
                    /*
                    if ($this->logger) {
                        $this->logger->debug(sprintf(
                            'Return %s for compare %s (null/emptystring)',
                            $result ? 'true' : 'false',
                            $compare
                        ));
                    }
                    */
                    return $result;
                }
                if ($newValue === null && is_string($oldValue) && mb_strlen($oldValue) == 0) {
                    $result = false;
                    /*
                    if ($this->logger) {
                        $this->logger->debug(sprintf(
                            'Return %s for compare %s (emptystring/null)',
                            $result ? 'true' : 'false',
                            $compare
                        ));
                    }
                    */
                    return $result;
                }

                $result = $oldValue !== $newValue;
                /*
                if ($this->logger) {
                    $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                }
                */
                return $result;
            } elseif ($compare == DataChange::COMPARE_CASE_INSENSITIVE) {
                $result = mb_strtolower($oldValue) !== mb_strtolower($newValue);
                /*
                if ($this->logger) {
                    $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                }
                */
                return $result;
            } elseif ($compare == DataChange::COMPARE_OBJECT_SERIALIZE) {
                $result = serialize($oldValue) == serialize($newValue);
                /*
                if ($this->logger) {
                    $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                }
                */
                return $result;
            } elseif ($compare == DataChange::COMPARE_OBJECT_EQUALS) {
                if (!$oldValue && !$newValue) {
                    $result = false;
                    /*
                    if ($this->logger) {
                        $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                    }
                    */
                    return $result;
                } elseif (!$oldValue && $newValue) {
                    $result = true;
                    /*
                    if ($this->logger) {
                        $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                    }
                    */
                    return $result;
                } elseif ($oldValue instanceof EqualsInterface) {
                    $result = !$oldValue->equals($newValue);
                    /*
                    if ($this->logger) {
                        $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                    }
                    */
                    return $result;
                } else {
                    $result = null;
                    /*
                    if ($this->logger) {
                        $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                    }
                    */
                    return $result;
                }
            } elseif ($compare == DataChange::COMPARE_INCREASE) {
                $result = $oldValue < $newValue;
                /*
                if ($this->logger) {
                    $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                }
                */
                return $result;
            } elseif ($compare == DataChange::COMPARE_DECREASE) {
                $result = $oldValue > $newValue;
                /*
                if ($this->logger) {
                    $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                }
                */
                return $result;
            } elseif ($compare == DataChange::COMPARE_TO_NULL) {
                $result = $newValue === null;
                /*
                if ($this->logger) {
                    $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                }
                */
                return $result;
            } elseif ($compare == DataChange::COMPARE_BACS) {
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
            } elseif ($compare == DataChange::COMPARE_JUDO) {
                if (!$oldValue instanceof JudoPaymentMethod || !$newValue instanceof JudoPaymentMethod) {
                    return false;
                }

                $result = $oldValue->getCardTokenHash() != $newValue->getCardTokenHash();

                if ($this->logger) {
                    $this->logger->debug(sprintf('Return %s for compare %s', $result ? 'true' : 'false', $compare));
                }

                return $result;
            } elseif ($compare == DataChange::COMPARE_PAYMENT_METHOD_CHANGED) {
                if ($oldValue instanceof JudoPaymentMethod && $newValue instanceof BacsPaymentMethod) {
                    return true;
                } elseif ($oldValue instanceof BacsPaymentMethod && $newValue instanceof JudoPaymentMethod) {
                    return true;
                } else {
                    return false;
                }
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
        foreach ($annotations as $property => $data) {
            if ($this->hasDataFieldChanged($eventArgs, get_class($document), $property, $data['comparison'])) {
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
                $items[$property->getName()] = [
                    'value' => $propertyAccessor->getValue($object, $property->getName()),
                    'comparison' => $propertyAnnotation->getComparison() ?: DataChange::COMPARE_EQUAL,
                ];
            }
        }

        return $items;
    }
}
