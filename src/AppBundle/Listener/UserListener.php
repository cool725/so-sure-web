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
    const DUPLICATE_EMAIL_CACHE_TIME = 300;

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var MailerService */
    protected $mailer;

    protected $redis;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param MailerService   $mailer
     * @param                 $redis
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer,
        $redis
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->redis = $redis;
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
        $key = sprintf('user:change-email:%s', $email);
        if ($this->redis->exists($key)) {
            return;
        }

        $this->mailer->sendTemplate(
            'Your email has been changed',
            $email,
            'AppBundle:Email:emailChanged.html.twig',
            ['user' => $user],
            'AppBundle:Email:emailChanged.txt.twig',
            ['user' => $user]
        );
        $this->redis->setex($key, self::DUPLICATE_EMAIL_CACHE_TIME, 1);
    }

    /**
     * @param UserEvent $event
     */
    public function onUserCreatedEvent(UserEvent $event)
    {
        $this->onUserCreatedUpdated($event);
    }

    /**
     * @param UserEvent $event
     */
    public function onUserUpdatedEvent(UserEvent $event)
    {
        $this->onUserCreatedUpdated($event);
    }
    
    private function onUserCreatedUpdated(UserEvent $event)
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
