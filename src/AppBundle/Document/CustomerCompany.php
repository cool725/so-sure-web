<?php
/**
 * Created by PhpStorm.
 * User: patrick
 * Date: 05/10/2018
 * Time: 11:22
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
class CustomerCompany extends BaseCompany
{
    /**
     * @MongoDB\ReferenceMany(targetDocument="Policy", mappedBy="company")
     */
    protected $policies;

    public function addPolicy(Policy $policy)
    {
        $policy->setCompany($this);
        $this->policies[] = $policy;
    }

    public function getPolicies()
    {
        return $this->policies;
    }
}
