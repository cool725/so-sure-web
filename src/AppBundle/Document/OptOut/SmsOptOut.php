<?php

namespace AppBundle\Document\OptOut;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Document\PhoneTrait;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\OptOut\SmsOptOutRepository")
 */
class SmsOptOut extends OptOut
{
    use PhoneTrait;

    /** @MongoDB\Field(type="string", nullable=false) */
    protected $mobile;

    public function getMobile()
    {
        return $this->mobile;
    }

    public function setMobile($mobile)
    {
        $this->mobile = $this->normalizeUkMobile($mobile);
    }
}
