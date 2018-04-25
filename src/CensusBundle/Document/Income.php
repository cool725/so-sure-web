<?php

namespace CensusBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @MongoDB\Document(collection="Income2014", repositoryClass="CensusBundle\Repository\IncomeRepository")
 */
class Income
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @MongoDB\Field(type="string", name="MSOA")
     * @MongoDB\Index(unique=true, sparse=true)
     */
    protected $msoa;

    /**
     * @MongoDB\Field(type="string", name="LOCAL_AUTHORITY_CODE")
     */
    protected $localAuthorityCode;

    /**
     * @MongoDB\EmbedOne(targetDocument="IncomeType")
     */
    protected $total;

    /**
     * @MongoDB\EmbedOne(targetDocument="IncomeType")
     */
    protected $net;

    /**
     * @MongoDB\EmbedOne(targetDocument="IncomeType")
     */
    protected $netBeforeHousing;

    /**
     * @MongoDB\EmbedOne(targetDocument="IncomeType")
     */
    protected $netAfterHousing;

    public function getMSOA()
    {
        return $this->msoa;
    }

    public function getTotal()
    {
        return $this->total;
    }

    public function getNet()
    {
        return $this->net;
    }

    public function getNetBeforeHousing()
    {
        return $this->netBeforeHousing;
    }

    public function getNetAfterHousing()
    {
        return $this->netAfterHousing;
    }
}
