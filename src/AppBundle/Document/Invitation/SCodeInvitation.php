<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\SCode;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\Invitation\SCodeInvitationRepository")
 */
class SCodeInvitation extends EmailInvitation
{
    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\SCode")
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

    public function getInvitationDetail()
    {
        return $this->getEmail();
    }

    public function getSCode()
    {
        return $this->scode;
    }

    public function setSCode(SCode $scode)
    {
        $this->scode = $scode;
    }

    public function getChannelDetails()
    {
        return $this->getSCode() ? $this->getSCode()->getType() : null;
    }

    /**
     * @InheritDoc
     */
    public function getSharerPolicy()
    {
        return $this->getInviteePolicy();
    }
}
