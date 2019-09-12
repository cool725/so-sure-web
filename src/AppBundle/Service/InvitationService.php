<?php
namespace AppBundle\Service;

use AppBundle\Document\IdentityLog;
use AppBundle\Document\Invitation\AppNativeShareInvitation;
use AppBundle\Exception\CannotApplyRewardException;
use AppBundle\Repository\ConnectionRepository;
use AppBundle\Repository\Invitation\EmailInvitationRepository;
use AppBundle\Repository\Invitation\FacebookInvitationRepository;
use AppBundle\Repository\Invitation\SCodeInvitationRepository;
use AppBundle\Repository\Invitation\SmsInvitationRepository;
use AppBundle\Repository\OptOut\EmailOptOutRepository;
use AppBundle\Classes\NoOp;
use AppBundle\Repository\OptOut\SmsOptOutRepository;
use AppBundle\Repository\SCodeRepository;
use AppBundle\Repository\UserRepository;
use Doctrine\ORM\Mapping\Id;
use Psr\Log\LoggerInterface;

use AppBundle\Classes\SoSure;

use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Charge;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Reward;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\SCodeInvitation;
use AppBundle\Document\Invitation\FacebookInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\PhoneTrait;

use AppBundle\Event\InvitationEvent;
use AppBundle\Event\ConnectionEvent;

use AppBundle\Exception\ClaimException;
use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\FullPotException;
use AppBundle\Exception\DuplicateInvitationException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\InvalidPolicyException;
use AppBundle\Exception\SelfInviteException;
use AppBundle\Exception\ConnectedInvitationException;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;

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

    /** @var RouterService */
    protected $routerService;

    /** @var ShortLinkService */
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

    /** @var EventDispatcherInterface */
    protected $dispatcher;

    /** @var MixpanelService */
    protected $mixpanel;

    public function setDispatcher($dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * @param DocumentManager          $dm
     * @param LoggerInterface          $logger
     * @param MailerService            $mailer
     * @param RouterService            $routerService
     * @param ShortLinkService         $shortLink
     * @param SmsService               $sms
     * @param RateLimitService         $rateLimit
     * @param PushService              $push
     * @param string                   $environment
     * @param EventDispatcherInterface $dispatcher
     * @param MixpanelService          $mixpanel
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer,
        RouterService $routerService,
        ShortLinkService $shortLink,
        SmsService $sms,
        RateLimitService $rateLimit,
        PushService $push,
        $environment,
        EventDispatcherInterface $dispatcher,
        MixpanelService $mixpanel
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->routerService = $routerService;
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
        return $this->routerService->generateUrl('invitation', [
            'id' => $invitation->getId()
        ]);
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

    public function setInvitee(Invitation $invitation, $user = null)
    {
        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        if ($invitation instanceof EmailInvitation) {
            /** @var User $user */
            $user = $userRepo->findOneBy(['emailCanonical' => $invitation->getEmail()]);
            if ($user && $invitation->getInviter()->getId() != $user->getId()) {
                $user->addReceivedInvitation($invitation);
                $this->sendEvent($invitation, InvitationEvent::EVENT_RECEIVED);
            }
        } elseif ($invitation instanceof SmsInvitation) {
            /** @var User $user */
            $user = $userRepo->findOneBy(['mobileNumber' => $invitation->getMobile()]);
            if ($user && $invitation->getInviter()->getId() != $user->getId()) {
                $user->addReceivedInvitation($invitation);
                $this->sendEvent($invitation, InvitationEvent::EVENT_RECEIVED);
            }
        } elseif ($invitation instanceof SCodeInvitation) {
            $scode = $invitation->getSCode();
            if ($scode->isStandard()) {
                if ($user && $invitation->getInviter()->getId() != $user->getId()) {
                    $user->addReceivedInvitation($invitation);
                    $this->sendEvent($invitation, InvitationEvent::EVENT_RECEIVED);
                }
            }
        } elseif ($invitation instanceof FacebookInvitation) {
            if ($user && $invitation->getInviter()->getId() != $user->getId()) {
                $user->addReceivedInvitation($invitation);
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
        // Invitations to @so-sure.com emails can only come from INVALID policies
        if (SoSure::hasSoSureEmail($email) && !$policy->hasPolicyPrefix(Policy::PREFIX_INVALID)) {
            throw new OptOutException(sprintf('Email %s has opted out', $email));
        }

        // INVALID prod policies can only invite @so-sure.com emails
        if ($policy->hasPolicyPrefix(Policy::PREFIX_INVALID) && !SoSure::hasSoSureEmail($email)) {
            throw new FullPotException('Invalid policies can not invite');
        }
    }

    public function validateNotConnectedByUser(Policy $policy, $user)
    {
        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $this->dm->getRepository(StandardConnection::class);
        $count = $connectionRepo->getConnectedByUserCount($policy, $user);
        if ($count > 0 && $count >= count($user->getValidPolicies(true))) {
            throw new ConnectedInvitationException(sprintf('You are already connected %d time(s)', $count));
        }

        // only 1 reward per user
        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $this->dm->getRepository(RewardConnection::class);
        $count = $connectionRepo->getConnectedByUserCount($policy, $user);
        if ($count > 0) {
            throw new ConnectedInvitationException('You are already connected');
        }

        if ($policy->getUser()->getId() == $user->getId()) {
            throw new SelfInviteException('User can not invite themself');
        }
    }

    public function validateNotConnectedByEmail(Policy $policy, $email)
    {
        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $this->dm->getRepository(StandardConnection::class);
        if ($connectionRepo->isConnectedByEmail($policy, $email)) {
            throw new ConnectedInvitationException('You are already connected');
        }

        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $this->dm->getRepository(RewardConnection::class);
        if ($connectionRepo->isConnectedByEmail($policy, $email)) {
            throw new ConnectedInvitationException('You are already connected');
        }

        /** @var EmailOptOutRepository $optOutRepo */
        $optOutRepo = $this->dm->getRepository(EmailOptOut::class);
        if ($optOutRepo->isOptedOut($email, EmailOptOut::OPTOUT_CAT_INVITATIONS)) {
            throw new OptOutException(sprintf('Email %s has opted out', $email));
        }

        if ($policy->getUser()->getEmailCanonical() == mb_strtolower($email)) {
            throw new SelfInviteException('User can not invite themself');
        }
    }

    public function validateNotConnectedByPolicy(Policy $sourcePolicy, Policy $linkedPolicy)
    {
        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $this->dm->getRepository(StandardConnection::class);
        if ($connectionRepo->isConnectedByPolicy($sourcePolicy, $linkedPolicy)) {
            throw new ConnectedInvitationException('You are already connected');
        }

        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $this->dm->getRepository(RewardConnection::class);
        if ($connectionRepo->isConnectedByPolicy($sourcePolicy, $linkedPolicy)) {
            throw new ConnectedInvitationException('You are already connected');
        }
    }

    public function validateNotRenewalPolicy(Policy $sourcePolicy, Policy $linkedPolicy)
    {
        if ($sourcePolicy->getNextPolicy() &&
            $sourcePolicy->getNextPolicy()->getId() == $linkedPolicy->getId()) {
            throw new SelfInviteException('Policy can not be linked to its renewal policy');
        } elseif ($sourcePolicy->getPreviousPolicy() &&
            $sourcePolicy->getPreviousPolicy()->getId() == $linkedPolicy->getId()) {
            throw new SelfInviteException('Policy can not be linked to its previous policy');
        }
    }

    public function inviteByEmail(Policy $policy, $email, $name = null, $skipSend = null, $location = null)
    {
        $this->validatePolicy($policy);
        $this->validateSoSurePolicyEmail($policy, $email);

        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        /** @var User $invitee */
        $invitee = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
        $inviteePolicies = 0;
        if ($invitee) {
            $inviteePolicies = count($invitee->getValidPolicies(true));
        }

        if ($invitee) {
            // if user exists, much better check, especially for multiple policies
            $this->validateNotConnectedByUser($policy, $invitee);
        } else {
            $this->validateNotConnectedByEmail($policy, $email);
        }

        $invitation = null;
        $isReinvite = false;
        /** @var EmailInvitationRepository $invitationRepo */
        $invitationRepo = $this->dm->getRepository(EmailInvitation::class);
        $prevInvitations = $invitationRepo->findDuplicate($policy, $email);
        $singleInvitationCount = 0;
        $totalInvitationCount = 0;
        foreach ($prevInvitations as $prevInvitation) {
            if ($prevInvitation->isCancelled()) {
                // Reinvitating a cancelled invitation, should re-active invitation
                $invitation = $prevInvitation;
                $invitation->setCancelled(null);
                $this->dm->flush();
                $isReinvite = true;
                break;
            } elseif ($prevInvitation->canReinvite() &&
                !$prevInvitation->isAccepted() && !$prevInvitation->isRejected()) {
                // A duplicate invitation can be considered a reinvitation
                $invitation = $prevInvitation;
                $isReinvite = true;
                break;
            }

            if (!$prevInvitation->isProcessed()) {
                $singleInvitationCount++;
            }
            $totalInvitationCount++;
            if ($singleInvitationCount >= 1 || $totalInvitationCount > $inviteePolicies) {
                throw new DuplicateInvitationException('Email was already invited to this policy');
            }
        }

        if (!$invitation) {
            $invitation = new EmailInvitation();
            $invitation->setEmail($email);
            $policy->addInvitation($invitation);
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
            $data = [
                'Invitation Method' => 'email',
            ];
            if ($location) {
                $data['Location'] = $location;
            }
            $this->mixpanel->queueTrackWithUser($invitation->getInviter(), MixpanelService::EVENT_INVITE, $data);
            $this->mixpanel->queuePersonIncrement('Number of Invites Sent', 1, $invitation->getInviter());
            $now = \DateTime::createFromFormat('U', time());
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

        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $this->dm->getRepository(StandardConnection::class);
        if ($connectionRepo->isConnectedBySms($policy, $mobile)) {
            throw new ConnectedInvitationException('You are already connected');
        }

        /** @var SmsOptOutRepository $optOutRepo */
        $optOutRepo = $this->dm->getRepository(SmsOptOut::class);
        if ($optOutRepo->isOptedOut($mobile, SmsOptOut::OPTOUT_CAT_INVITATIONS)) {
            return null;
        }

        if ($policy->getUser()->getMobileNumber() == $mobile) {
            throw new SelfInviteException('User can not invite themself');
        }

        $invitation = null;
        /** @var SmsInvitationRepository $invitationRepo */
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
            $policy->addInvitation($invitation);
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
            $this->mixpanel->queuePersonIncrement('Number of Invites Sent', 1, $invitation->getInviter());
            $now = \DateTime::createFromFormat('U', time());
            $this->mixpanel->queuePersonProperties([
                'Last Invite Sent' => $now->format(\DateTime::ATOM),
            ], false, $invitation->getInviter());
        }

        return $invitation;
    }

    public function inviteBySCode(Policy $policy, $code, \DateTime $date = null, $sdk = IdentityLog::SDK_UNKNOWN)
    {
        // check scode for url and resolve
        $code = $this->resolveSCode($code);

        /** @var SCodeRepository $repo */
        $repo = $this->dm->getRepository(SCode::class);
        /** @var SCode $scode */
        $scode = $repo->findOneBy(['code' => $code]);
        if (!$scode) {
            throw new NotFoundHttpException();
        }

        // If someone accidently enters a multipay code, transform request to the standard scode
        if ($scode->isMultiPay()) {
            $scode = $scode->getPolicy()->getStandardSCode();
        }

        if ($scode->isStandard()) {
            $user = $scode->getPolicy()->getUser();
        } elseif ($scode->isReward()) {
            $user = $scode->getReward()->getUser();
        } else {
            throw new \Exception(sprintf('Unimplemented scode (%s) invitation (type: %s)', $code, $scode->getType()));
        }

        $this->validatePolicy($policy);
        $this->validateSoSurePolicyEmail($policy, $user->getEmail());
        try {
            $this->validateNotConnectedByUser($policy, $user);
        } catch (SelfInviteException $e) {
            if (in_array($sdk, [IdentityLog::SDK_ANDROID, IdentityLog::SDK_IOS])) {
                $appNativeShare = new AppNativeShareInvitation();
                $policy->addInvitation($appNativeShare);

                $this->dm->persist($appNativeShare);
                $this->dm->flush();
            }

            throw $e;
        }

        if ($scode->isReward()) {
            if (!$this->runRewardValidation($policy, $scode)) {
                throw new CannotApplyRewardException(
                    sprintf(
                        "Promo Code %s could not be applied",
                        $scode->getCode()
                    )
                );
            }
            $this->addReward($policy, $scode->getReward());
        }

        $this->setSCodeLeadSource($scode, $policy, $user, $date);

        $inviteePolicies = 0;
        if ($scode->isStandard()) {
            $inviteePolicies = count($user->getValidPolicies(true));
        }

        $invitation = null;
        $isReinvite = false;
        /** @var SCodeInvitationRepository $invitationRepo */
        $invitationRepo = $this->dm->getRepository(SCodeInvitation::class);
        $prevInvitations = $invitationRepo->findDuplicate($policy, $scode);
        $singleInvitationCount = 0;
        $totalInvitationCount = 0;
        foreach ($prevInvitations as $prevInvitation) {
            if ($prevInvitation->isCancelled()) {
                // Reinvitating a cancelled invitation, should re-active invitation
                $invitation = $prevInvitation;
                $invitation->setCancelled(null);
                $this->dm->flush();
                $isReinvite = true;
                break;
            } elseif ($prevInvitation->canReinvite() &&
                !$prevInvitation->isAccepted() && !$prevInvitation->isRejected()) {
                // A duplicate invitation can be considered a reinvitation
                $invitation = $prevInvitation;
                $isReinvite = true;
                break;
            }

            if (!$prevInvitation->isProcessed()) {
                $singleInvitationCount++;
            }
            $totalInvitationCount++;
            if ($singleInvitationCount >= 1 || $totalInvitationCount > $inviteePolicies) {
                throw new DuplicateInvitationException('SCode was already invited to this policy');
            }
        }

        if (!$invitation) {
            $invitation = new SCodeInvitation();
            $invitation->setEmail($user->getEmail());
            $invitation->setSCode($scode);
            $policy->addInvitation($invitation);
            $invitation->setName($user->getName());
            $this->setInvitee($invitation, $user);
            $invitation->invite();

            if ($scode->isReward()) {
                $invitation->setAccepted(\DateTime::createFromFormat('U', time()));
            }
            $this->dm->persist($invitation);
            $this->dm->flush();

            if (!$scode->isReward()) {
                $link = $this->getShortLink($invitation);
                $invitation->setLink($link);
            }
            $this->dm->flush();
        }

        if ($scode->isStandard()) {
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
                'Invitation Method' => 'scode',
            ]);
            $this->mixpanel->queuePersonIncrement('Number of Invites Sent', 1, $invitation->getInviter());
            $now = \DateTime::createFromFormat('U', time());
            $this->mixpanel->queuePersonProperties([
                'Last Invite Sent' => $now->format(\DateTime::ATOM),
            ], false, $invitation->getInviter());
        }

        return $invitation;
    }

    private function setSCodeLeadSource(SCode $scode, Policy $policy, User $user, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $date->sub(new \DateInterval('P1D'));

        // if there isn't a lead source and its been less than a day, assume that
        // the lead source was due to the scode
        if (!$policy->getLeadSource() && $policy->getStart() > $date) {
            $policy->setLeadSource(Lead::LEAD_SOURCE_SCODE);
            $policy->setLeadSourceDetails($scode->getCode());
            // if policy is being set, user probably needs setting as well
            if (!$user->getLeadSource()) {
                $user->setLeadSource(Lead::LEAD_SOURCE_SCODE);
                $user->setLeadSourceDetails($scode->getCode());
            }
        }
    }

    public function inviteByFacebookId(Policy $policy, $facebookId)
    {
        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['facebookId' => (string) $facebookId]);
        if (!$user) {
            throw new NotFoundHttpException();
        }

        $this->validatePolicy($policy);
        $this->validateSoSurePolicyEmail($policy, $user->getEmail());
        $this->validateNotConnectedByUser($policy, $user);

        $inviteePolicies = count($user->getValidPolicies(true));

        $invitation = null;
        $isReinvite = false;
        /** @var FacebookInvitationRepository $invitationRepo */
        $invitationRepo = $this->dm->getRepository(FacebookInvitation::class);
        $prevInvitations = $invitationRepo->findDuplicate($policy, $facebookId);
        $singleInvitationCount = 0;
        $totalInvitationCount = 0;
        foreach ($prevInvitations as $prevInvitation) {
            if ($prevInvitation->isCancelled()) {
                // Reinvitating a cancelled invitation, should re-active invitation
                $invitation = $prevInvitation;
                $invitation->setCancelled(null);
                $this->dm->flush();
                $isReinvite = true;
                break;
            } elseif ($prevInvitation->canReinvite() &&
                !$prevInvitation->isAccepted() && !$prevInvitation->isRejected()) {
                // A duplicate invitation can be considered a reinvitation
                $invitation = $prevInvitation;
                $isReinvite = true;
                break;
            }

            if (!$prevInvitation->isProcessed()) {
                $singleInvitationCount++;
            }
            $totalInvitationCount++;
            if ($singleInvitationCount >= 1 || $totalInvitationCount > $inviteePolicies) {
                throw new DuplicateInvitationException('Facebook user was already invited to this policy');
            }
        }

        if (!$invitation) {
            $invitation = new FacebookInvitation();
            $invitation->setFacebookId($facebookId);
            $invitation->setEmail($user->getEmail());
            $policy->addInvitation($invitation);
            $invitation->setName($user->getName());
            $this->setInvitee($invitation, $user);
            $invitation->invite();
            $this->dm->persist($invitation);
            $this->dm->flush();

            $link = $this->getShortLink($invitation);
            $invitation->setLink($link);
            $this->dm->flush();
        }

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
            'Invitation Method' => 'facebook',
        ]);
        $this->mixpanel->queuePersonIncrement('Number of Invites Sent', 1, $invitation->getInviter());
        $now = \DateTime::createFromFormat('U', time());
        $this->mixpanel->queuePersonProperties([
            'Last Invite Sent' => $now->format(\DateTime::ATOM),
        ], false, $invitation->getInviter());

        return $invitation;
    }

    public function resolveSCode($scode)
    {
        if (mb_strlen($scode) <= 8) {
            return $scode;
        }

        if (mb_stripos($scode, "http") === false) {
            $url = sprintf("http://%s", $scode);
        } else {
            $url = $scode;
        }

        try {
            $client = new Client();
            $res = $client->request('GET', $url, [
                'on_stats' => function (TransferStats $stats) use (&$url) {
                    $url = $stats->getEffectiveUri();
                }
            ]);

            // Branch now performs a javascript redirect using window.location
            $host = mb_strtolower(parse_url($url, PHP_URL_HOST));
            if (in_array($host, ['sosure.app.link', 'sosure.test-app.link'])) {
                $body = (string) $res->getBody();
                $pattern = '/.*window\.location\s*=.*["](http[^"]*)["].*/m';
                $matches = array();
                preg_match($pattern, $body, $matches);
                if (count($matches) >= 2 && filter_var($matches[1], FILTER_VALIDATE_URL)) {
                    $url = $matches[1];
                }
            }
            $parts = explode('/', parse_url($url, PHP_URL_PATH));

            return $parts[count($parts) - 1];
        } catch (\Exception $e) {
            $this->logger->warning(sprintf('Failed to resolve scode %s', $scode), ['exception' => $e]);
        }

        return $scode;
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
        $message = null;
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
        } else {
            $this->logger->warning('Dispatcher is disabled for Invitation Service');
        }
    }

    /**
     * Send an invitation email
     *
     * @param Invitation $invitation Invitation and not EmailInvitation as SmsInvitations can be accepted, etc
     */
    protected function sendEmail(Invitation $invitation, $type)
    {
        $from = null;
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
            $from = ['noreply@wearesosure.com' => $invitation->getInviter()->getName()];
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

        /** @var EmailOptOutRepository $optOutRepo */
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
                ['invitation' => $invitation],
                null,
                null,
                $from
            );
            $invitation->setStatus(EmailInvitation::STATUS_SENT);
        } catch (\Exception $e) {
            $invitation->setStatus(EmailInvitation::STATUS_FAILED);
            $this->logger->error(sprintf(
                'Failed sending invite to %s Ex: %s',
                $to,
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
        /** @var SmsOptOutRepository $optOutRepo */
        $optOutRepo = $this->dm->getRepository(SmsOptOut::class);
        $optouts = $optOutRepo->findOptOut($invitation->getMobile(), SmsOptOut::OPTOUT_CAT_INVITATIONS);
        if (count($optouts) > 0) {
            $invitation->setStatus(SmsInvitation::STATUS_SKIPPED);
            return;
        }
        $smsTemplate = sprintf('AppBundle:Sms:%s.txt.twig', $type);
        $status = $this->sms->sendTemplate(
            $invitation->getMobile(),
            $smsTemplate,
            ['invitation' => $invitation],
            $invitation->getPolicy(),
            Charge::TYPE_SMS_INVITATION,
            $this->debug
        );
        $invitation->setStatus($status ? (SmsInvitation::STATUS_SENT) : (SmsInvitation::STATUS_FAILED));
    }

    protected function validatePolicy(Policy $policy)
    {
        $prefix = $policy->getPolicyPrefix($this->environment);
        if (!$policy->isValidPolicy($prefix)) {
            throw new InvalidPolicyException(sprintf(
                "Policy must be pending/active before inviting/connecting (%s)",
                $policy->getPolicyNumber()
            ));
        }
        if (!in_array($policy->getStatus(), [
            Policy::STATUS_ACTIVE,
            Policy::STATUS_UNPAID,
        ])) {
            throw new InvalidPolicyException(sprintf(
                'Policy must be active before inviting/connecting (%s)',
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

    public function accept(Invitation $invitation, Policy $inviteePolicy, \DateTime $date = null, $skipSend = false)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if ($invitation->isProcessed()) {
            throw new ProcessedException("Invitation has already been processed");
        }

        if (!$this->rateLimit->replayData($invitation->getId(), $inviteePolicy->getId())) {
            throw new ProcessedException("Invitation is processing");
        }

        $inviterPolicy = $invitation->getPolicy();

        $this->validateSoSurePolicyEmail($inviterPolicy, $inviteePolicy->getUser()->getEmail());
        $this->validateSoSurePolicyEmail($inviteePolicy, $inviterPolicy->getUser()->getEmail());

        $this->validatePolicy($inviterPolicy);
        $this->validatePolicy($inviteePolicy);

        // The invitation should never be sent in the first place, but in case
        // there was perhaps an email update in the meantime
        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $this->dm->getRepository(StandardConnection::class);
        if ($connectionRepo->isConnectedByPolicy($inviterPolicy, $inviteePolicy) ||
            $connectionRepo->isConnectedByPolicy($inviteePolicy, $inviterPolicy)) {
                throw new ConnectedInvitationException('You  are already connected');
        }

        /** @var User $inviter */
        $inviter = $invitation->getInviter();
        /** @var User $invitee */
        $invitee = $invitation->getInvitee();

        // If there was a concellation in the network, new connection should replace the cancelled connection
        $inviteeConnection = null;
        if ($inviter) {
            $inviteeConnection = $this->addConnection(
                $inviteePolicy,
                $inviter,
                $inviterPolicy,
                $invitation,
                $date
            );
        }
        $inviterConnection = null;
        if ($invitee) {
            $inviterConnection = $this->addConnection(
                $inviterPolicy,
                $invitee,
                $inviteePolicy,
                $invitation,
                $date
            );
        }


        $invitation->setAccepted($date);

        // add connection bonus if there is one.
        /** @var RewardRepository */
        $rewardRepo = $this->dm->getRepository(Reward::class);
        $connectionBonus = $rewardRepo->getConnectionBonus($date);
        if ($connectionBonus && $connectionBonus->canApply($policy, $date)) {
            try {
                $this->addReward($invitation->getSharerPolicy(), $connectionBonus);
            } catch (\Exception $e) {
                NoOp::ignore([]);
            }
        }

        // TODO: ensure transaction state for this one....
        $this->dm->flush();

        // Notify inviter of acceptance
        if (!$skipSend) {
            $this->sendEmail($invitation, self::TYPE_EMAIL_ACCEPT);
            $this->sendPush($invitation, PushService::MESSAGE_CONNECTED);
        }
        $this->sendEvent($invitation, InvitationEvent::EVENT_ACCEPTED);
        if ($this->dispatcher) {
            $this->dispatcher->dispatch(ConnectionEvent::EVENT_CONNECTED, new ConnectionEvent($inviteeConnection));
            $this->dispatcher->dispatch(ConnectionEvent::EVENT_CONNECTED, new ConnectionEvent($inviterConnection));
        }

        $now = \DateTime::createFromFormat('U', time());
        $this->mixpanel->queueTrackWithUser($invitation->getInviter(), MixpanelService::EVENT_CONNECTION_COMPLETE, [
            'Connection Value' => $inviterConnection->getTotalValue(),
            'Policy Id' => $inviterPolicy->getId(),
        ]);
        $this->mixpanel->queuePersonProperties([
            'Last connection complete' => $now->format(\DateTime::ATOM),
        ], false, $invitation->getInviter());

        if ($inviteeConnection) {
            $this->mixpanel->queueTrackWithUser($invitation->getInvitee(), MixpanelService::EVENT_CONNECTION_COMPLETE, [
                'Connection Value' => $inviteeConnection->getTotalValue(),
                'Policy Id' => $inviteePolicy->getId(),
            ]);
        }
        $this->mixpanel->queuePersonProperties([
            'Last connection complete' => $now->format(\DateTime::ATOM),
        ], false, $invitation->getInvitee());

        return $inviteeConnection;
    }

    public function connect(Policy $policyA, Policy $policyB, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $this->validatePolicy($policyA);
        $this->validatePolicy($policyB);

        $this->validateNotConnectedByPolicy($policyA, $policyB);
        $this->validateNotConnectedByPolicy($policyB, $policyA);

        $this->validateNotRenewalPolicy($policyA, $policyB);
        $this->validateNotRenewalPolicy($policyB, $policyA);

        $connectionA = $this->addConnection(
            $policyA,
            $policyB->getUser(),
            $policyB,
            null,
            $date
        );
        $connectionB = $this->addConnection(
            $policyB,
            $policyA->getUser(),
            $policyA,
            null,
            $date
        );
        $this->dm->flush();
    }

    protected function addConnection(
        Policy $policy,
        User $linkedUser,
        Policy $linkedPolicy,
        Invitation $invitation = null,
        \DateTime $date = null
    ) {
        if ($policy->getId() == $linkedPolicy->getId()) {
            throw new \Exception('Unable to connect to the same policy');
        }

        if (!$date) {
            $date = new \DateTime();
        }

        $connectionValue = $policy->getAllowedConnectionValue($date);
        $promoConnectionValue = $policy->getAllowedPromoConnectionValue($date);

        $connection = new StandardConnection();

        // If there was a concellation in the network, new connection should replace the cancelled connection
        if ($replacementConnection = $policy->getUnreplacedConnectionCancelledPolicyInLast30Days($date)) {
            $connectionValue = $replacementConnection->getInitialValue();
            $promoConnectionValue = $replacementConnection->getInitialPromoValue();
            $replacementConnection->setReplacementConnection($connection);
            $replacementConnection->setValue(0);
            $replacementConnection->setPromoValue(0);
        }

        $connection->setLinkedUser($linkedUser);
        $connection->setLinkedPolicy($linkedPolicy);
        $connection->setValue($connectionValue);
        $connection->setPromoValue($promoConnectionValue);
        if ($invitation) {
            $connection->setInvitation($invitation);
            $connection->setInitialInvitationDate($invitation->getCreated());
        }
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

        $invitation->setRejected(\DateTime::createFromFormat('U', time()));
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

        $invitation->setCancelled(\DateTime::createFromFormat('U', time()));
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
        if (!$invitation->getPolicy() || !in_array($invitation->getPolicy()->getStatus(), [
            Policy::STATUS_ACTIVE,
            Policy::STATUS_UNPAID,
        ])) {
            return false;
        }

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
            $invitation->setStatus(Invitation::STATUS_SKIPPED);
            $this->dm->flush();
            $this->logger->info('SMS Reinvitations are not currently supported');

            return false;
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

        /** @var EmailOptOutRepository $optOutRepo */
        $optOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $optouts = $optOutRepo->findOptOut($email, $category);
        return count($optouts) > 0;
    }

    public function optout($email, $category, $location)
    {
        /** @var EmailOptOutRepository $optoutRepo */
        $optoutRepo = $this->dm->getRepository(EmailOptOut::class);
        if (!$optoutRepo->isOptedOut($email, $category)) {
            $optout = new EmailOptOut();
            $optout->addCategory($category);
            $optout->setLocation($location);
            $optout->setEmail($email);

            $this->dm->persist($optout);
        }
        $this->dm->flush();
    }

    public function rejectAllInvitations($email)
    {
        /** @var EmailInvitationRepository $inviteRepo */
        $inviteRepo = $this->dm->getRepository(EmailInvitation::class);
        $invitations = $inviteRepo->findBy(['email' => mb_strtolower($email)]);
        foreach ($invitations as $invitation) {
            if (!$invitation->isProcessed()) {
                $invitation->setRejected(\DateTime::createFromFormat('U', time()));
            }
        }
        $this->dm->flush();
    }

    public function addReward(Policy $policy, Reward $reward, $amount = null)
    {
        if (!$amount) {
            $amount = $reward->getDefaultValue();
        }

        if ($policy->hasMonetaryClaimed()) {
            throw new \InvalidArgumentException(sprintf(
                'Unable to add bonus. Poliicy %s has a monetary claim',
                $policy->getId()
            ));
        }
        if (count($policy->getNetworkClaims(true)) > 0) {
            throw new \InvalidArgumentException(sprintf(
                'Policy %s has a network claim',
                $policy->getId()
            ));
        }
        $connection = new RewardConnection();
        $policy->addConnection($connection);
        $connection->setLinkedUser($reward->getUser());
        $connection->setPromoValue($amount);
        $reward->addConnection($connection);
        $reward->updatePotValue();
        $policy->updatePotValue();
        $this->dm->persist($connection);
        $this->dm->flush();

        return $connection;
    }

    public function runRewardValidation(Policy $policy, SCode $scode)
    {
        /**
         * TODO: make this run from the scodes validation rules
         * for now, this will include the rules for the SOJULY19 scode only
         */
        if ($scode->getCode() !== "SOJULY19") {
            return true;
        }
        $user = $policy->getUser();
        if (!$user->hasPolicy()) {
            return false;
        }
        if (count($user->getAllPolicies()) > 1) {
            return false;
        }
        $start = $policy->getStart();
        $now = new \DateTime();
        $diff = $now->diff($start);
        if ($diff->d > 6 || ($diff->m > 0 || $diff->y > 0)) {
            return false;
        }
        return true;
    }
}
