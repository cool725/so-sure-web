<?php

namespace CensusBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @MongoDB\EmbeddedDocument
 */
class IncomeType
{
    /**
     * @MongoDB\Field(type="float")
     */
    protected $income;
    
    /**
     * @MongoDB\Field(type="float")
     */
    protected $upperIncome;

    /**
     * @MongoDB\Field(type="float")
     */
    protected $lowerIncome;

    /**
     * @MongoDB\Field(type="float")
     */
    protected $confidenceInterval;

    public function getIncome()
    {
        return $this->income;
    }

    public function getUpperIncome()
    {
        return $this->upperIncome;
    }

    public function getLowerIncome()
    {
        return $this->lowerIncome;
    }

    public function getConfidenceInterval()
    {
        return $this->confidenceInterval;
    }
}
