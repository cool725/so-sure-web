<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\EmbeddedDocument
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"phone"="AppBundle\Document\PhonePremium"})
 */
abstract class Premium
{
    /** @MongoDB\Date() */
    protected $validFrom;

    /** @MongoDB\Date() */
    protected $validTo;

    public function __construct()
    {
    }

    public function getValidFrom()
    {
        return $this->validFrom;
    }

    public function setValidFrom($validFrom)
    {
        $this->validFrom = $validFrom;
    }

    public function getValidTo()
    {
        return $this->validTo;
    }

    public function setValidTo($validTo)
    {
        $this->validTo = $validTo;
    }
}
