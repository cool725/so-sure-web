<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\Connection;
use AppBundle\Document\Charge;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\User;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;

use AppBundle\Exception\ClaimException;
use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\FullPotException;
use AppBundle\Exception\DuplicateInvitationException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\InvalidPolicyException;
use AppBundle\Exception\SelfInviteException;
use AppBundle\Exception\ConnectedInvitationException;
use AppBundle\Document\PhoneTrait;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InvitationService
{
    use PhoneTrait;

    const TYPE_EMAIL_ACCEPT = 'accept';
    const TYPE_EMAIL_REJECT = 'reject';
    const TYPE_EMAIL_CANCEL = 'cancel';
    const TYPE_EMAIL_INVITE = 'invite';
    const TYPE_EMAIL_REINVITE = 'reinvite';
    const TYPE_EMAIL_INVITE_USER = 'invite-user';

    const TYPE_SMS_INVITE = 'invite';
    const TYPE_SMS_INVITE_USER = 'invite-user';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var MailerService */
    protected $mailer;
    protected $templating;
    protected $router;

    /** @var ShortLink */
    protected $shortLink;

    /** @var SmsService */
    protected $sms;

    /** @var RateLimitService */
    protected $rateLimit;

    /** @var PushService */
    protected $push;

    /** @var boolean */
    protected $debug;

    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param MailerService    $mailer
     * @param                  $templating
     * @param                  $router
     * @param ShortLinkService $shortLink
     * @param SmsService       $sms
     * @param RateLimitService $rateLimit
     * @param PushService      $push
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        \Swift_Mailer $mailer,
        $templating,
        $router,
        ShortLinkService $shortLink,
        SmsService $sms,
        RateLimitService $rateLimit,
        PushService $push
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->templating = $templating;
        $this->router = $router->getRouter();
        $this->shortLink = $shortLink;
        $this->sms = $sms;
        $this->rateLimit = $rateLimit;
        $this->push = $push;
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * Get link for an invitation
     *
     * @param Invitation $invitation
     */
    public function getLink(Invitation $invitation)
    {
        return $this->router->generate('invitation', [
            'id' => $invitation->getId()
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Get Short Link for an invitation
     *
     * @param Invitation $invitation
     */
    public function getShortLink(Invitation $invitation)
    {
        $url = $this->getLink($invitation);
        $invitationUrl = $this->shortLink->addShortLink($url);

        return $invitationUrl;
    }

    public function setInvitee(Invitation $invitation)
    {
        $userRepo = $this->dm->getRepository(User::class);
        if ($invitation instanceof EmailInvitation) {
            $user = $userRepo->findOneBy(['emailCanonical' => $invitation->getEmail()]);
            if ($user && $invitation->getInviter()->getId() != $user->getId()) {
                $invitation->setInvitee($user);
            }
        } elseif ($invitation instanceof SmsInvitation) {
            $user = $userRepo->findOneBy(['mobileNumber' => $invitation->getMobile()]);
            if ($user && $invitation->getInviter()->getId() != $user->getId()) {
                $invitation->setInvitee($user);
            }
        }
    }

    public function inviteByEmail(Policy $policy, $email, $name = null, $skipSend = null)
    {
        $this->validatePolicy($policy);

        $connectionRepo = $this->dm->getRepository(Connection::class);
        if ($connectionRepo->isConnectedByEmail($policy, $email)) {
            throw new ConnectedInvitationException('You are already connected');
        }

        $optOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $optouts = $optOutRepo->findOptOut($email, EmailOptOut::OPTOUT_CAT_INVITATIONS);
        if (count($optouts) > 0) {
            throw new OptOutException(sprintf('Email %s has opted out', $email));
        }

        if ($policy->getUser()->getEmailCanonical() == strtolower($email)) {
            throw new SelfInviteException('User can not invite themself');
        }

        $invitation = null;
        $invitationRepo = $this->dm->getRepository(EmailInvitation::class);
        $prevInvitations = $invitationRepo->findDuplicate($policy, $email);
        foreach ($prevInvitations as $prevInvitation) {
            if ($prevInvitation->isAccepted() || $prevInvitation->isRejected()) {
                throw new DuplicateInvitationException('Email was already invited to this policy');
            } elseif ($prevInvitation->isCancelled()) {
                // Reinvitating a cancelled invitation, should re-active invitation
                $invitation = $prevInvitation;
                $invitation->setCancelled(null);
                $this->dm->flush();
            }
        }

        if (!$invitation) {
            $invitation = new EmailInvitation();
            $invitation->setEmail($email);
            $invitation->setPolicy($policy);
            $invitation->setName($name);
            $this->setInvitee($invitation);
            $invitation->invite();
            $this->dm->persist($invitation);
            $this->dm->flush();

            $link = $this->getShortLink($invitation);
            $invitation->setLink($link);
            $this->dm->flush();
        }

        if (!$skipSend) {
            if ($invitation->getInvitee()) {
                $this->sendEmail($invitation, self::TYPE_EMAIL_INVITE_USER);
            } else {
                $this->sendEmail($invitation, self::TYPE_EMAIL_INVITE);
            }
            $this->sendPush($invitation, PushService::MESSAGE_INVITATION);
        }

        return $invitation;
    }

    public function inviteBySms(Policy $policy, $mobile, $name = null, $skipSend = null)
    {
        $mobile = $this->normalizeUkMobile($mobile);
        $this->validatePolicy($policy);

        $connectionRepo = $this->dm->getRepository(Connection::class);
        if ($connectionRepo->isConnectedBySms($policy, $mobile)) {
            throw new ConnectedInvitationException('You are already connected');
        }

        $optOutRepo = $this->dm->getRepository(SmsOptOut::class);
        $optouts = $optOutRepo->findOptOut($mobile, SmsOptOut::OPTOUT_CAT_INVITATIONS);
        if (count($optouts) > 0) {
            return null;
        }

        if ($policy->getUser()->getMobileNumber() == $mobile) {
            throw new SelfInviteException('User can not invite themself');
        }

        $invitation = null;
        $invitationRepo = $this->dm->getRepository(SmsInvitation::class);
        $prevInvitations = $invitationRepo->findDuplicate($policy, $mobile);
        foreach ($prevInvitations as $prevInvitation) {
            if ($prevInvitation->isAccepted() || $prevInvitation->isRejected()) {
                throw new DuplicateInvitationException('Mobile was already invited to this policy');
            } elseif ($prevInvitation->isCancelled()) {
                // Reinvitating a cancelled invitation, should re-active invitation
                $invitation = $prevInvitation;
                $invitation->setCancelled(null);
                $this->dm->flush();
            }
        }

        if (!$invitation) {
            $invitation = new SmsInvitation();
            $invitation->setMobile($mobile);
            $invitation->setPolicy($policy);
            $invitation->setName($name);
            $this->setInvitee($invitation);
            $invitation->invite();
            $this->dm->persist($invitation);
            $this->dm->flush();

            $link = $this->getShortLink($invitation);
            $invitation->setLink($link);
            $this->dm->flush();
        }

        if (!$skipSend) {
            if ($invitation->getInvitee()) {
                $this->sendSms($invitation, self::TYPE_SMS_INVITE_USER);
            } else {
                $this->sendSms($invitation, self::TYPE_SMS_INVITE);
            }
            $this->sendPush($invitation, PushService::MESSAGE_INVITATION);
        }

        return $invitation;
    }

    public function inviteBySCode(Policy $policy, $scode)
    {
        $this->validatePolicy($policy);

        $repo = $this->dm->getRepository(SCode::class);
        $scodeObj = $repo->findOneBy(['code' => $scode]);
        if (!$scodeObj) {
            throw new NotFoundHttpException();
        }
        $user = $scodeObj->getPolicy()->getUser();

        return $this->inviteByEmail($policy, $user->getEmail(), $user->getName());
    }

    /**
     * Send a push notification
     *
     * @param Invitation $invitation Invitation and not EmailInvitation as SmsInvitations can be accepted, etc
     */
    protected function sendPush(Invitation $invitation, $type)
    {
        $user = null;
        $badge = null;
        if ($type == PushService::MESSAGE_CONNECTED) {
            $user = $invitation->getInviter();
            $message = sprintf(
                '%s has accepted your connection!',
                $invitation->getInviteeName()
            );
        } elseif ($type == PushService::MESSAGE_INVITATION) {
            $user = $invitation->getInvitee();
            $message = sprintf(
                '%s wants to connect with you!',
                $invitation->getInviterName()
            );
            // TODO: Enable this when iOS app is ready to handle
            // $badge = count($user->getUnprocessedReceivedInvitations());
        }

        if (!$user) {
            return null;
        }

        $this->push->sendToUser($type, $user, $message, $badge);
    }

    /**
     * Send an invitation email
     *
     * @param Invitation $invitation Invitation and not EmailInvitation as SmsInvitations can be accepted, etc
     */
    protected function sendEmail(Invitation $invitation, $type)
    {
        if ($this->debug) {
            return;
        }

        $to = null;
        $subject = null;
        $htmlTemplate = sprintf('AppBundle:Email:invitation/%s.html.twig', $type);
        $textTemplate = sprintf('AppBundle:Email:invitation/%s.txt.twig', $type);
        if ($type == self::TYPE_EMAIL_ACCEPT) {
            $to = $invitation->getInviter()->getEmail();
            $subject = sprintf('%s has accepted your invitation to so-sure', $invitation->getInviteeName());
        } elseif ($type == self::TYPE_EMAIL_CANCEL) {
            // Only able to do for EmailInvitations
            if (!$invitation instanceof EmailInvitation) {
                return;
            }
            $to = $invitation->getEmail();
            $subject = sprintf('Sorry, %s has cancelled your invitation to so-sure', $invitation->getInviterName());
        } elseif ($type == self::TYPE_EMAIL_INVITE) {
            // Only able to do for EmailInvitations
            if (!$invitation instanceof EmailInvitation) {
                return;
            }
            $to = $invitation->getEmail();
            $subject = sprintf('%s has invited you to so-sure', $invitation->getInviterName());
        } elseif ($type == self::TYPE_EMAIL_INVITE_USER) {
            // Only able to do for EmailInvitations
            if (!$invitation instanceof EmailInvitation) {
                return;
            }
            $to = $invitation->getEmail();
            $subject = sprintf('%s wants to connect with your on so-sure', $invitation->getInviterName());
        } elseif ($type == self::TYPE_EMAIL_REINVITE) {
            // Only able to do for EmailInvitations
            if (!$invitation instanceof EmailInvitation) {
                return;
            }
            $to = $invitation->getEmail();
            $subject = sprintf('%s has re-invited you to so-sure', $invitation->getInviterName());
        } elseif ($type == self::TYPE_EMAIL_REJECT) {
            $to = $invitation->getInviter()->getEmail();
            $subject = sprintf(
                'Sorry, %s is not interested in your invitation to so-sure',
                $invitation->getInviteeName()
            );
        } else {
            throw new \InvalidArgumentException(sprintf('Unknown type %s', $type));
        }

        try {
            $this->mailer->sendTemplate(
                $subject,
                $to,
                $htmlTemplate,
                ['invitation' => $invitation],
                $textTemplate,
                ['invitation' => $invitation]
            );
            $invitation->setStatus(EmailInvitation::STATUS_SENT);
        } catch (\Exception $e) {
            $invitation->setStatus(EmailInvitation::STATUS_FAILED);
            $this->logger->error(sprintf(
                'Failed sending invite to %s Ex: %s',
                $invitation->getEmail(),
                $e->getMessage()
            ));
        }
    }

    /**
     * Send an invitation sms
     *
     * @param SmsInvitation $invitation
     */
    protected function sendSms(SmsInvitation $invitation, $type)
    {
        if ($this->debug) {
            // Useful for testing
            $this->addSmsCharge($invitation);

            return;
        }

        $smsTemplate = sprintf('AppBundle:Sms:%s.txt.twig', $type);
        $message = $this->templating->render($smsTemplate, ['invitation' => $invitation]);
        if ($this->sms->send($invitation->getMobile(), $message)) {
            $invitation->setStatus(SmsInvitation::STATUS_SENT);
        } else {
            $invitation->setStatus(SmsInvitation::STATUS_FAILED);
        }

        $this->addSmsCharge($invitation);
    }

    public function addSmsCharge(SmsInvitation $invitation)
    {
        $charge = new Charge();
        $charge->setType(Charge::TYPE_SMS);
        $charge->setUser($invitation->getInviter());
        $charge->setPolicy($invitation->getPolicy());
        $charge->setDetails($invitation->getMobile());
        $this->dm->persist($charge);
        $this->dm->flush();
    }

    protected function validatePolicy(Policy $policy)
    {
        if (!$policy->isPolicy()) {
            throw new InvalidPolicyException('Policy must be pending/active before inviting/connecting');
        }
        if ($policy->isPotCompletelyFilled()) {
            throw new FullPotException('Pot is full and no longer able to invite/connect');
        }
        if ($policy->hasMonetaryClaimed()) {
            throw new ClaimException('Policy has had a monetary claimed and is no longer able to invite/connect');
        }
    }

    public function accept(Invitation $invitation, Policy $inviteePolicy, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        if ($invitation->isProcessed()) {
            throw new ProcessedException("Invitation has already been processed");
        }

        $inviterPolicy = $invitation->getPolicy();

        $this->validatePolicy($inviterPolicy);
        $this->validatePolicy($inviteePolicy);

        // The invitation should never be sent in the first place, but in case
        // there was perhaps an email update in the meantime
        $connectionRepo = $this->dm->getRepository(Connection::class);
        if ($invitation instanceof EmailInvitation) {
            if ($connectionRepo->isConnectedByEmail($invitation->getPolicy(), $invitation->getEmail())) {
                throw new ConnectedInvitationException('You are already connected');
            }
        } elseif ($invitation instanceof SmsInvitation) {
            if ($connectionRepo->isConnectedBySms($invitation->getPolicy(), $invitation->getMobile())) {
                throw new ConnectedInvitationException('You are already connected');
            }
        }

        // If there was a concellation in the network, new connection should replace the cancelled connection
        $this->addConnection($inviteePolicy, $invitation->getInviter(), $inviterPolicy, $invitation, $date);
        $this->addConnection($inviterPolicy, $invitation->getInvitee(), $inviteePolicy, $invitation, $date);

        $invitation->setAccepted($date);

        // TODO: ensure transaction state for this one....
        $this->dm->flush();

        // Notify inviter of acceptance
        $this->sendEmail($invitation, self::TYPE_EMAIL_ACCEPT);
        $this->sendPush($invitation, PushService::MESSAGE_CONNECTED);
    }

    protected function addConnection(
        Policy $policy,
        User $linkedUser,
        Policy $linkedPolicy,
        Invitation $invitation,
        \DateTime $date = null
    ) {
        if ($policy->getId() == $linkedPolicy->getId()) {
            throw new \Exception('Unable to connect to the same policy');
        }

        $connectionValue = $policy->getAllowedConnectionValue($date);
        $promoConnectionValue = $policy->getAllowedPromoConnectionValue($date);
        // If there was a concellation in the network, new connection should replace the cancelled connection
        if ($replacementConnection = $policy->getUnreplacedConnectionCancelledPolicyInLast30Days($date)) {
            $connectionValue = $replacementConnection->getInitialValue();
            $promoConnectionValue = $replacementConnection->getInitialPromoValue();
            $replacementConnection->setReplacementUser($linkedUser);
        }
        $connection = new Connection();
        $connection->setLinkedUser($linkedUser);
        $connection->setLinkedPolicy($linkedPolicy);
        $connection->setValue($connectionValue);
        $connection->setPromoValue($promoConnectionValue);
        $connection->setInvitation($invitation);
        $connection->setInitialInvitationDate($invitation->getCreated());
        $policy->addConnection($connection);
        $policy->updatePotValue();
    }

    public function reject(Invitation $invitation)
    {
        if ($invitation->isProcessed()) {
            throw new ProcessedException("Invitation has already been processed");
        }

        $invitation->setRejected(new \DateTime());
        $this->dm->flush();

        // Notify inviter of rejection
        $this->sendEmail($invitation, self::TYPE_EMAIL_REJECT);
    }

    public function cancel(Invitation $invitation)
    {
        if ($invitation->isProcessed()) {
            throw new ProcessedException("Invitation has already been processed");
        }

        $invitation->setCancelled(new \DateTime());
        $this->dm->flush();

        // Notify invitee of cancellation
        if ($invitation instanceof EmailInvitation) {
            $this->sendEmail($invitation, self::TYPE_EMAIL_CANCEL);
        } else {
            // TODO: SMS Cancellation
            \AppBundle\Classes\NoOp::noOp([null]);
        }
    }

    public function reinvite(Invitation $invitation)
    {
        if ($invitation->isProcessed()) {
            throw new ProcessedException("Invitation has already been processed");
        }

        if (!$invitation->canReinvite()) {
            throw new RateLimitException('Reinvite limit exceeded');
        }

        $inviterPolicy = $invitation->getPolicy();
        $this->validatePolicy($inviterPolicy);

        if ($invitation instanceof EmailInvitation) {
            // Send reinvitation
            $this->sendEmail($invitation, self::TYPE_EMAIL_REINVITE);
            $invitation->reinvite();
            $this->dm->flush();
            $this->sendPush($invitation, PushService::MESSAGE_INVITATION);
        } elseif ($invitation instanceof SmsInvitation) {
            $this->sendSms($invitation);
            $invitation->reinvite();
            $this->dm->flush();
            $this->sendPush($invitation, PushService::MESSAGE_INVITATION);
        } else {
            throw new \Exception('Unknown invitation type. Unable to reinvite.');
        }

        return true;
    }
}
