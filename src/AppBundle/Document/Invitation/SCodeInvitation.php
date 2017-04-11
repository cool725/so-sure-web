<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\SCode;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\Invitation\SCodeInvitationRepository")
 */
class SCodeInvitation extends Invitation
{
    /**
     * @MongoDB\ReferenceOne(targetDocument="SCode")
     * @Gedmo\Versioned
     */
    protected $scode;

    public function isSingleUse()
    {
        return true;
    }

    public function getChannel()
    {
        return 'scode';
    }

    public function getMaxReinvitations()
    {
        return 5;
    }

    public function getInvitationDetail()
    {
        return $this->getSCode()->getCode();
    }

    public function getSCode()
    {
        return $this->scode;
    }

    public function setSCode(SCode $scode)
    {
        $this->scode = $scode;
    }
}
