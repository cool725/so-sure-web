<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;

use AppBundle\Classes\SoSure;

use AppBundle\Document\Connection;
use AppBundle\Document\Charge;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\PhoneTrait;

use AppBundle\Event\InvitationEvent;

use AppBundle\Exception\ClaimException;
use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\FullPotException;
use AppBundle\Exception\DuplicateInvitationException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\InvalidPolicyException;
use AppBundle\Exception\SelfInviteException;
use AppBundle\Exception\ConnectedInvitationException;

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

    protected $environment;
    protected $dispatcher;

    /** @var MixpanelService */
    protected $mixpanel;

    public function setDispatcher($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param MailerService    $mailer
     * @param                  $router
     * @param ShortLinkService $shortLink
     * @param SmsService       $sms
     * @param RateLimitService $rateLimit
     * @param PushService      $push
     * @param string           $environment
     * @param                  $dispatcher
     * @param MixpanelService  $mixpanel
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer,
        $router,
        ShortLinkService $shortLink,
        SmsService $sms,
        RateLimitService $rateLimit,
        PushService $push,
        $environment,
        $dispatcher,
        MixpanelService $mixpanel
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->router = $router->getRouter();
        $this->shortLink = $shortLink;
        $this->sms = $sms;
        $this->rateLimit = $rateLimit;
        $this->push = $push;
        $this->environment = $environment;
        $this->dispatcher = $dispatcher;
        $this->mixpanel = $mixpanel;
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
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
                $this->sendEvent($invitation, InvitationEvent::EVENT_RECEIVED);
            }
        } elseif ($invitation instanceof SmsInvitation) {
            $user = $userRepo->findOneBy(['mobileNumber' => $invitation->getMobile()]);
            if ($user && $invitation->getInviter()->getId() != $user->getId()) {
                $invitation->setInvitee($user);
                $this->sendEvent($invitation, InvitationEvent::EVENT_RECEIVED);
            }
        }
    }

    /**
     * Ensure that we don't invite/accept between invalid and valid policies
     *
     * @param Policy $policy Policy to check if its valid/invalid
     * @param string $email  Email to check if its so-sure / non so-sure
     */
    public function validateSoSurePolicyEmail(Policy $policy, $email)
    {
        // For Prod, Invitations to @so-sure.com emails can only come from INVALID prod policies
        if ($this->environment == 'prod') {
            if (SoSure::hasSoSureEmail($email) && !$policy->hasPolicyPrefix(Policy::PREFIX_INVALID)) {
                throw new OptOutException(sprintf('Email %s has opted out', $email));
            }
        }

        // INVALID prod policies can only invite @so-sure.com emails
        if ($policy->hasPolicyPrefix(Policy::PREFIX_INVALID) && !SoSure::hasSoSureEmail($email)) {
            throw new FullPotException('Invalid policies can not invite');
        }
    }

    public function inviteByEmail(Policy $policy, $email, $name = null, $skipSend = null)
    {
        $this->validatePolicy($policy);
        $this->validateSoSurePolicyEmail($policy, $email);

        $connectionRepo = $this->dm->getRepository(Connection::class);
        if ($connectionRepo->isConnectedByEmail($policy, $email)) {
            throw new ConnectedInvitationException('You are already connected');
        }

        $optOutRepo = $this->dm->getRepository(EmailOptOut::class);
        if ($optOutRepo->isOptedOut($email, EmailOptOut::OPTOUT_CAT_INVITATIONS)) {
            throw new OptOutException(sprintf('Email %s has opted out', $email));
        }

        if ($policy->getUser()->getEmailCanonical() == strtolower($email)) {
            throw new SelfInviteException('User can not invite themself');
        }

        $invitation = null;
        $isReinvite = false;
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
                $isReinvite = true;
            } elseif ($prevInvitation->canReinvite()) {
                // A duplicate invitation can be considered a reinvitation
                $invitation = $prevInvitation;
                $isReinvite = true;
            } else {
                throw new DuplicateInvitationException('Email was already invited to this policy');
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
                // User invite is the same for reinvite
                $this->sendEmail($invitation, self::TYPE_EMAIL_INVITE_USER);
            } else {
                if ($isReinvite) {
                    $this->sendEmail($invitation, self::TYPE_EMAIL_REINVITE);
                } else {
                    $this->sendEmail($invitation, self::TYPE_EMAIL_INVITE);
                }
            }
            $this->sendPush($invitation, PushService::MESSAGE_INVITATION);
            $this->sendEvent($invitation, InvitationEvent::EVENT_INVITED);
            $this->mixpanel->queueTrackWithUser($invitation->getInviter(), MixpanelService::EVENT_INVITE, [
                'Invitation Method' => 'email',
            ]);
            $now = new \DateTime();
            $this->mixpanel->queuePersonProperties([
                'Last Invite Sent' => $now->format(\DateTime::ATOM),
            ], false, $invitation->getInviter());
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
        if ($optOutRepo->isOptedOut($mobile, SmsOptOut::OPTOUT_CAT_INVITATIONS)) {
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
            $this->sendEvent($invitation, InvitationEvent::EVENT_INVITED);
            $this->mixpanel->queueTrackWithUser($invitation->getInviter(), MixpanelService::EVENT_INVITE, [
                'Invitation Method' => 'sms',
            ]);
            $now = new \DateTime();
            $this->mixpanel->queuePersonProperties([
                'Last Invite Sent' => $now->format(\DateTime::ATOM),
            ], false, $invitation->getInviter());
        }

        return $invitation;
    }

    public function inviteBySCode(Policy $policy, $scode, \DateTime $date = null)
    {
        $this->validatePolicy($policy);

        $repo = $this->dm->getRepository(SCode::class);
        $scodeObj = $repo->findOneBy(['code' => $scode]);
        if (!$scodeObj) {
            throw new NotFoundHttpException();
        }
        $user = $scodeObj->getPolicy()->getUser();

        if (!$date) {
            $date = new \DateTime();
        }
        $date->sub(new \DateInterval('P1D'));

        // if there isn't a lead source and its been less than a day, assume that
        // the lead source was due to the scode
        if (!$policy->getLeadSource() && $policy->getStart() > $date) {
            $policy->setLeadSource(Lead::LEAD_SOURCE_SCODE);
            // if policy is being set, user probably needs setting as well
            if (!$user->getLeadSource()) {
                $user->setLeadSource(Lead::LEAD_SOURCE_SCODE);
            }
        }

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
        $messageData = null;
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
            $messageData = ['id' => $invitation->getId()];
            // TODO: Enable this when iOS app is ready to handle
            // $badge = count($user->getUnprocessedReceivedInvitations());
        }

        if (!$user) {
            return null;
        }

        $this->push->sendToUser($type, $user, $message, $badge, $messageData);
    }

    protected function sendEvent(Invitation $invitation, $eventType)
    {
        // Primarily used to allow tests to avoid triggering policy events
        if ($this->dispatcher) {
            $this->dispatcher->dispatch($eventType, new InvitationEvent($invitation));
        }
    }

    /**
     * Send an invitation email
     *
     * @param Invitation $invitation Invitation and not EmailInvitation as SmsInvitations can be accepted, etc
     */
    protected function sendEmail(Invitation $invitation, $type)
    {
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
            $subject = sprintf('%s wants to connect with you on so-sure', $invitation->getInviterName());
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

        $optOutRepo = $this->dm->getRepository(EmailOptOut::class);
        if ($optOutRepo->isOptedOut($to, EmailOptOut::OPTOUT_CAT_INVITATIONS)) {
            $invitation->setStatus(EmailInvitation::STATUS_SKIPPED);

            return;
        }

        if ($this->debug) {
            return;
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
        $optOutRepo = $this->dm->getRepository(SmsOptOut::class);
        $optouts = $optOutRepo->findOptOut($invitation->getMobile(), SmsOptOut::OPTOUT_CAT_INVITATIONS);
        if (count($optouts) > 0) {
            $invitation->setStatus(SmsInvitation::STATUS_SKIPPED);
            return;
        }

        if ($this->debug) {
            // Useful for testing
            $this->addSmsCharge($invitation);

            return;
        }

        $smsTemplate = sprintf('AppBundle:Sms:%s.txt.twig', $type);
        if ($this->sms->sendTemplate($invitation->getMobile(), $smsTemplate, ['invitation' => $invitation])) {
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
        $prefix = $policy->getPolicyPrefix($this->environment);
        if (!$policy->isValidPolicy($prefix)) {
            throw new InvalidPolicyException(sprintf(
                'Policy must be pending/active before inviting/connecting (%s)',
                $policy->getPolicyNumber()
            ));
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

        $this->validateSoSurePolicyEmail($inviterPolicy, $inviteePolicy->getUser()->getEmail());
        $this->validateSoSurePolicyEmail($inviteePolicy, $inviterPolicy->getUser()->getEmail());

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
        $inviteeConnection = $this->addConnection(
            $inviteePolicy,
            $invitation->getInviter(),
            $inviterPolicy,
            $invitation,
            $date
        );
        $inviterConnection = $this->addConnection(
            $inviterPolicy,
            $invitation->getInvitee(),
            $inviteePolicy,
            $invitation,
            $date
        );

        $invitation->setAccepted($date);

        // TODO: ensure transaction state for this one....
        $this->dm->flush();

        // Notify inviter of acceptance
        $this->sendEmail($invitation, self::TYPE_EMAIL_ACCEPT);
        $this->sendPush($invitation, PushService::MESSAGE_CONNECTED);
        $this->sendEvent($invitation, InvitationEvent::EVENT_ACCEPTED);

        $now = new \DateTime();
        $this->mixpanel->queueTrackWithUser($invitation->getInviter(), MixpanelService::EVENT_CONNECTION_COMPLETE, [
            'Connection Value' => $inviterConnection->getTotalValue(),
        ]);
        $this->mixpanel->queuePersonProperties([
            'Last connection complete' => $now->format(\DateTime::ATOM),
        ], false, $invitation->getInviter());

        $this->mixpanel->queueTrackWithUser($invitation->getInvitee(), MixpanelService::EVENT_CONNECTION_COMPLETE, [
            'Connection Value' => $inviteeConnection->getTotalValue(),
        ]);
        $this->mixpanel->queuePersonProperties([
            'Last connection complete' => $now->format(\DateTime::ATOM),
        ], false, $invitation->getInvitee());

        return $inviteeConnection;
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
        $connection->setExcludeReporting(!$policy->isValidPolicy());
        $policy->addConnection($connection);
        $policy->updatePotValue();

        return $connection;
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
        $this->sendEvent($invitation, InvitationEvent::EVENT_REJECTED);
    }

    public function cancel(Invitation $invitation)
    {
        if ($invitation->isProcessed()) {
            throw new ProcessedException("Invitation has already been processed");
        }

        $invitation->setCancelled(new \DateTime());
        $this->dm->flush();

        $this->sendEvent($invitation, InvitationEvent::EVENT_CANCELLED);
        // Given that we can now display the invitation page even if there isn't a valid invite,
        // we shouldn't send a cancellation email - we still want them to signup
        /*
        if ($invitation instanceof EmailInvitation) {
            $this->sendEmail($invitation, self::TYPE_EMAIL_CANCEL);
        } else {
            // TODO: SMS Cancellation
            \AppBundle\Classes\NoOp::ignore([null]);
        }
        */
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
            $this->sendEvent($invitation, InvitationEvent::EVENT_REINVITED);
        } elseif ($invitation instanceof SmsInvitation) {
            throw new \Exception('SMS Reinvitations are not currently supported');
            /*
            $this->sendSms($invitation, self::TYPE_SMS_REINVITE);
            $invitation->reinvite();
            $this->dm->flush();
            $this->sendPush($invitation, PushService::MESSAGE_INVITATION);
            */
        } else {
            throw new \Exception('Unknown invitation type. Unable to reinvite.');
        }

        return true;
    }

    public function isOptedOut($email, $category = null)
    {
        if (!$category) {
            $category = EmailOptOut::OPTOUT_CAT_ALL;
        }

        $optOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $optouts = $optOutRepo->findOptOut($email, $category);
        return count($optouts) > 0;
    }

    public function optout($email, $category = null)
    {
        if (!$category) {
            $category = EmailOptOut::OPTOUT_CAT_ALL;
        }

        $optoutRepo = $this->dm->getRepository(EmailOptOut::class);
        if (!$optoutRepo->isOptedOut($email, $category)) {
            $optout = new EmailOptOut();
            $optout->setCategory($category);
            $optout->setEmail($email);

            $this->dm->persist($optout);
        }
        $this->dm->flush();
    }

    public function optin($email, $category = null)
    {
        if (!$category) {
            $category = EmailOptOut::OPTOUT_CAT_ALL;
        }

        $optoutRepo = $this->dm->getRepository(EmailOptOut::class);
        $optOuts = $optoutRepo->findOptOut($email, $category);
        foreach ($optOuts as $optOut) {
            $this->dm->remove($optOut);
        }
        $this->dm->flush();
    }

    public function rejectAllInvitations($email)
    {
        $inviteRepo = $this->dm->getRepository(EmailInvitation::class);
        $invitations = $inviteRepo->findBy(['email' => strtolower($email)]);
        foreach ($invitations as $invitation) {
            if (!$invitation->isProcessed()) {
                $invitation->setRejected(new \DateTime());
            }
        }
        $this->dm->flush();
    }
}
