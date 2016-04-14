<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\Invitation\SmsInvitationRepository")
 */
class SmsInvitation extends Invitation
{
    /** @MongoDB\Field(type="string", nullable=false) */
    protected $mobile;

    public function isSingleUse()
    {
        return true;
    }

    public function getChannel()
    {
        return 'sms';
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
