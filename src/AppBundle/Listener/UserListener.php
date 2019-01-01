<?php

namespace AppBundle\Listener;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Lead;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Service\MailerService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Psr\Log\LoggerInterface;
use FOS\UserBundle\Event\FilterUserResponseEvent;
use FOS\UserBundle\FOSUserEvents;

class UserListener
{
    const DUPLICATE_EMAIL_CACHE_TIME = 300;

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var MailerService */
    protected $mailer;

    /** @var Client */
    protected $redis;

    /** @var FOSUBUserProvider */
    protected $userService;

    /**
     * @param DocumentManager   $dm
     * @param LoggerInterface   $logger
     * @param MailerService     $mailer
     * @param Client            $redis
     * @param FOSUBUserProvider $userService
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer,
        Client $redis,
        FOSUBUserProvider $userService
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->redis = $redis;
        $this->userService = $userService;
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
        if ($this->redis->exists($key) == 1) {
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
        $this->onUserCreatedUpdated($event, true);
    }

    /**
     * @param UserEvent $event
     */
    public function onUserPasswordChangedEvent(UserEvent $event)
    {
        if (!$this->userService->previousPasswordCheck($event->getUser())) {
            throw new \Exception(sprintf(
                'User %s has attempted to re-use a previous password',
                $event->getUser()->getId()
            ));
        }
    }

    private function onUserCreatedUpdated(UserEvent $event, $uow = false)
    {
        $user = $event->getUser();
        $email = $user->getEmailCanonical();
        $mobile = $user->getMobileNumber();
        $update = false;

        if ($email) {
            $emailInvitationRepo = $this->dm->getRepository(EmailInvitation::class);
            $invitations = $emailInvitationRepo->findBy(['email' => $email, 'invitee' => null]);
            foreach ($invitations as $invitation) {
                $user->addReceivedInvitation($invitation);
                $update = true;
            }

            $leadRepo = $this->dm->getRepository(Lead::class);
            /** @var Lead $lead */
            $lead = $leadRepo->findOneBy(['emailCanonical' => $email]);
            if ($lead) {
                $lead->populateUser($user);
            }
        }

        if ($mobile) {
            $smsInvitationRepo = $this->dm->getRepository(SmsInvitation::class);
            $invitations = $smsInvitationRepo->findBy(['mobile' => $mobile, 'invitee' => null]);
            foreach ($invitations as $invitation) {
                $user->addReceivedInvitation($invitation);
                $update = true;
            }

            $leadRepo = $this->dm->getRepository(Lead::class);
            /** @var Lead $lead */
            $lead = $leadRepo->findOneBy(['mobileNumber' => $mobile]);
            if ($lead) {
                $lead->populateUser($user);
            }
        }

        if ($update) {
            if ($uow) {
                $uow = $this->dm->getUnitOfWork();
                $meta = $this->dm->getClassMetadata(User::class);
                $uow->recomputeSingleDocumentChangeSet($meta, $user);
            } else {
                $this->dm->flush();
            }
        }
    }
}
