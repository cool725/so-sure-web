<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class SmsInvitation extends Invitation
{
    /** @MongoDB\Field(type="string", nullable=false) */
    protected $mobile;

    public function isSingleUse()
    {
        return true;
    }
    
    public function getMobile()
    {
        return $this->mobile;
    }

    public function setMobile($mobile)
    {
        $this->mobile = $mobile;
    }
}
