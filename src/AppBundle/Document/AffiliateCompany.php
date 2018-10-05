<?php
/**
 * Created by PhpStorm.
 * User: patrick
 * Date: 05/10/2018
 * Time: 11:24
 */

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable
 */
class AffiliateCompany extends BaseCompany
{

    /**
     * @Assert\Range(min=0,max=20)
     * @MongoDB\Field(type="float")
     */
    protected $cpa;

    /**
     * @Assert\Range(min=0,max=90)
     * @MongoDB\Field(type="integer")
     */
    protected $days;

    public function setCPA(float $cpa)
    {
        $this->cpa = $cpa;
    }

    public function getCPA()
    {
        return $this->cpa;
    }

    public function setdays(int $days)
    {
        $this->days = $days;
    }

    public function getdays()
    {
        return $this->days;
    }
}