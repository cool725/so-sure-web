<?php

namespace CensusBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(repositoryClass="CensusBundle\Repository\PostCodeRepository")
 * @MongoDB\Index(keys={"Location"="2dsphere"}, sparse="true")
 */
class Postcode
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @MongoDB\Field(type="string", name="Postcode")
     * @MongoDB\Index(unique=true, sparse=true)
     */
    protected $postcode;

    /**
     * @MongoDB\Field(type="string", name="E")
     */
    protected $eastling;

    /**
     * @MongoDB\Field(type="string", name="N")
     */
    protected $northling;

    /**
     * @MongoDB\EmbedOne(targetDocument="Coordinates", name="Location")
     */
    protected $location;

    public function getPostcode()
    {
        return $this->postcode;
    }

    public function setPostcode($postcode)
    {
        $this->postcode = $postcode;
    }

    public function getLocation()
    {
        return $this->location;
    }
}
