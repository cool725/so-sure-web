<?php

namespace AppBundle\Listener;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use Doctrine\ODM\MongoDB\DocumentManager;

class UserListener
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
     * @param UserEvent $event
     */
    public function onUserUpdatedEvent(UserEvent $event)
    {
        $user = $event->getUser();
        $email = $user->getEmailCanonical();
        $mobile = $user->getMobileNumber();
        $flush = false;

        if ($email) {
            $emailInvitationRepo = $this->dm->getRepository(EmailInvitation::class);
            $invitations = $emailInvitationRepo->findBy(['email' => $email, 'invitee' => null]);
            foreach ($invitations as $invitation) {
                $user->addReceivedInvitation($invitation);
                $flush = true;
            }
        }

        if ($mobile) {
            $smsInvitationRepo = $this->dm->getRepository(SmsInvitation::class);
            $invitations = $smsInvitationRepo->findBy(['mobile' => $mobile, 'invitee' => null]);
            foreach ($invitations as $invitation) {
                $user->addReceivedInvitation($invitation);
                $flush = true;
            }
        }

        if ($flush) {
            $this->dm->flush();
        }
    }
}
