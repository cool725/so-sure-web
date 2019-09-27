<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Document\PhoneTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\Invitation\InvitationRepository")
 */
class AppNativeShareInvitation extends Invitation
{
    use PhoneTrait;

    public function isSingleUse()
    {
        return false;
    }

    public function getChannel()
    {
        return 'app-native';
    }

    public function getMaxReinvitations()
    {
        return 0;
    }

    public function getInvitationDetail()
    {
        return null;
    }

    public function getChannelDetails()
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getSharer()
    {
        return $this->getInviter();
    }
}
