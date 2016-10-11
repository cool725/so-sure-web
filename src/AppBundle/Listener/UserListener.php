<?php

namespace AppBundle\Listener;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Service\MailerService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

class UserListener
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var MailerService */
    protected $mailer;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param MailerService   $mailer
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
    }

    /**
     * @param UserEmailEvent $event
     */
    public function onUserEmailChangedEvent(UserEmailEvent $event)
    {
        $this->sendUserEmailChangedEmail($event->getUser(), $event->getOldEmail());
        $this->sendUserEmailChangedEmail($event->getUser(), $event->getUser()->getEmail());
    }

    /**
     * Send user an email changed email
     *
     * @param User $user
     */
    public function sendUserEmailChangedEmail(User $user, $email)
    {
        $this->mailer->sendTemplate(
            'Your email has been changed',
            $email,
            'AppBundle:Email:emailChanged.html.twig',
            ['user' => $user],
            'AppBundle:Email:emailChanged.txt.twig',
            ['user' => $user]
        );
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
