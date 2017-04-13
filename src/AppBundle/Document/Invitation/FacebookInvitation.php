<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\Invitation\EmailInvitationRepository")
 */
class FacebookInvitation extends EmailInvitation
{
    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $facebookId;

    public function isSingleUse()
    {
        return true;
    }

    public function getChannel()
    {
        return 'facebook';
    }

    public function getMaxReinvitations()
    {
        return 5;
    }

    public function getInvitationDetail()
    {
        return $this->getEmail();
    }

    public function getFacebookId()
    {
        return $this->facebookId;
    }

    public function setFacebookId($facebookId)
    {
        $this->facebookId = $facebookId;
    }

    public function getChannelDetails()
    {
        return $this->getFacebookId();
    }
}
