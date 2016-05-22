<?php

namespace AppBundle\Listener;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\User;
use AppBundle\Event\InvitationEvent;
use Doctrine\ODM\MongoDB\DocumentManager;

class InvitationListener
{
    /** @var DocumentManager */
    protected $dm;

    /**
     * @param DocumentManager $dm
     */
    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    /**
     * @param InvitationEvent $event
     */
    public function onInvitationEvent(InvitationEvent $event)
    {
        $userRepo = $this->dm->getRepository(User::class);
        $invitation = $event->getInvitation();
        if ($invitation instanceof EmailInvitation) {
            $user = $userRepo->findOneBy(['emailCanonical' => $invitation->getEmail()]);
            if ($user && $invitation->getInviter()->getId() != $user->getId()) {
                $invitation->setInvitee($user);
                $this->dm->flush();
            }
        } elseif ($invitation instanceof SmsInvitation) {
            $user = $userRepo->findOneBy(['mobileNumber' => $invitation->getMobile()]);
            if ($user && $invitation->getInviter()->getId() != $user->getId()) {
                $invitation->setInvitee($user);
                $this->dm->flush();
            }
        }
    }
}
