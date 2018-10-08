<?php

namespace AppBundle\Listener;

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

    protected function hasDataChanged(PreUpdateEventArgs $eventArgs, $class, $fields, $compare = self::COMPARE_EQUAL)
    {
        foreach ($fields as $field) {
            if ($this->hasDataChanged($eventArgs, $class, $field, $compare)) {
                return true;
            }
        }

        return false;
    }

    private function hasDataFieldChanged(PreUpdateEventArgs $eventArgs, $class, $field, $compare = self::COMPARE_EQUAL)
    {
        $document = $eventArgs->getDocument();
        if (!$document instanceof $class) {
            return null;
        }

        $oldValue = $eventArgs->getOldValue($field);
        $newValue = $eventArgs->getNewValue($field);

        if ($eventArgs->hasChangedField($field)) {
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
            } else {
                throw new \Exception(sprintf('Unknown comparision %s', $compare));
            }
        }

        return false;
    }
}
