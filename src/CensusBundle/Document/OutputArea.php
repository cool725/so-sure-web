<?php

namespace CensusBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document()
 */
class OutputArea
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
     * @MongoDB\Field(type="string", name="OA")
     */
    protected $oa;

    /**
     * @MongoDB\Field(type="string", name="LSOA")
     * @MongoDB\Index()
     */
    protected $lsoa;

    /**
     * @MongoDB\Field(type="string", name="MSOA")
     */
    protected $msoa;

    /**
     * @MongoDB\Field(type="string", name="LOCAL_AUTHORITY_CODE")
     */
    protected $localAuthorityCode;

    /**
     * @MongoDB\Field(type="string", name="LOCAL_AUTHORITY_NAME")
     */
    protected $localAuthorityName;

    public function getPostcode()
    {
        return $this->postcode;
    }

    public function getOA()
    {
        return $this->oa;
    }

    public function getLSOA()
    {
        return $this->lsoa;
    }

    public function getMSOA()
    {
        return $this->msoa;
    }
}
