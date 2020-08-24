<?php

namespace AppBundle\Repository;

use AppBundle\Document\Subvariant;
use Doctrine\ODM\MongoDB\MongoDBException;

/**
 * Repository for subvariants.
 */
class SubvariantRepository extends BaseDocumentRepository
{
    /**
     * Finds a subvariant by it's name.
     * @param string $name is the name of the subvariant to find.
     * @return Subvariant|null is the subvariant which was found or null if nothing is found.
     */
    public function getSubvariantByName($name)
    {
        return $this->findOneBy(['name' => $name]);
    }
}
