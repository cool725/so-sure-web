<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Document\PhoneTrait;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\Invitation\SmsInvitationRepository")
 */
class SmsInvitation extends Invitation
{
    use PhoneTrait;

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

    public function getMaxReinvitations()
    {
        return 2;
    }

    public function getInvitationDetail()
    {
        return $this->getMobile();
    }

    public function getMobile()
    {
        return $this->mobile;
    }

    public function setMobile($mobile)
    {
        $this->mobile = $this->normalizeUkMobile($mobile);
    }
}
