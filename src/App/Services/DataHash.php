<?php

namespace App\Services;

use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * TODO: reconsider if this ought to have it's own folder.
 * TODO: actually, consider if this should not be deleted.
 * Take an array of data and hash it to a single value. If the data changes, the value changes.
 *
 * This value (an MD5 hash) is stored in the DB, so we know if an external system need to be updated.
 *
 * The value in the DB record/document is ONLY updated on an `update()` call
 */
class DataHash
{
    /** @var string */
    private $fieldName;
    /** @var \Symfony\Component\PropertyAccess\PropertyAccessor */
    private $propertyAccessor;

    public function __construct(string $fieldName)
    {
        $this->fieldName = $fieldName;

        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
    }

    private function getHashValue($obj)
    {
        return $this->propertyAccessor->getValue($obj, $this->fieldName);
    }
    private function setHashValue($obj, $value)
    {
        $this->propertyAccessor->setValue($obj, $this->fieldName, $value);
    }

    public function isChanged($obj, array $dataToHash): bool
    {
        if (null === $this->getHashValue($obj)) {
            return true;    // a null will need to be changed
        }

        return $this->hash($dataToHash) !== $this->getHashValue($obj);
    }

    public function update($obj, array $dataToHash): bool
    {
        $oldValue = $this->getHashValue($obj);
        $this->setHashValue($obj, $this->hash($dataToHash));

        return $oldValue !== $this->getHashValue($obj);
    }

    private function hash(array $dataToHash): string
    {
        return md5(json_encode($dataToHash, JSON_PRESERVE_ZERO_FRACTION));
    }
}
