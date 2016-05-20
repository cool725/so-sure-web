<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\Connection;
use AppBundle\Document\Policy;
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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

class InvitationService
{
    const TYPE_EMAIL_ACCEPT = 'accept';
    const TYPE_EMAIL_REJECT = 'reject';
    const TYPE_EMAIL_CANCEL = 'cancel';
    const TYPE_EMAIL_INVITE = 'invite';
    const TYPE_EMAIL_REINVITE = 'reinvite';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var \Swift_Mailer */
    protected $mailer;
    protected $templating;
    protected $router;

    /** @var ShortLink */
    protected $shortLink;

    /** @var SmsService */
    protected $sms;

    /** @var RateLimitService */
    protected $rateLimit;

    /** @var boolean */
    protected $debug;

    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param \Swift_Mailer    $mailer
     * @param                  $templating
     * @param                  $router
     * @param ShortLinkService $shortLink
     * @param SmsService       $sms
     * @param RateLimitService $rateLimit
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        \Swift_Mailer $mailer,
        $templating,
        $router,
        ShortLinkService $shortLink,
        SmsService $sms,
        RateLimitService $rateLimit
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->templating = $templating;
        $this->router = $router->getRouter();
        $this->shortLink = $shortLink;
        $this->sms = $sms;
        $this->rateLimit = $rateLimit;
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

    public function inviteByEmail(Policy $policy, $email, $name = null)
    {
        $this->validatePolicy($policy);

        $invitationRepo = $this->dm->getRepository(EmailInvitation::class);
        $prevInvitations = $invitationRepo->findDuplicate($policy, $email);
        if (count($prevInvitations) > 0) {
            throw new DuplicateInvitationException('Email was already invited to this policy');
        }

        $optOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $optouts = $optOutRepo->findOptOut($email, EmailOptOut::OPTOUT_CAT_INVITATIONS);
        if (count($optouts) > 0) {
            return null;
        }

        if ($policy->getUser()->getEmailCanonical() == strtolower($email)) {
            throw new SelfInviteException('User can not invite themself');
        }

        $invitation = new EmailInvitation();
        $invitation->setEmail($email);
        $invitation->setPolicy($policy);
        $invitation->setName($name);
        $invitation->invite();
        $this->dm->persist($invitation);
        $this->dm->flush();

        $link = $this->getShortLink($invitation);
        $invitation->setLink($link);
        $this->dm->flush();

        $this->sendEmail($invitation, self::TYPE_EMAIL_INVITE);

        return $invitation;
    }

    public function inviteBySms(Policy $policy, $mobile, $name = null)
    {
        $this->validatePolicy($policy);

        $invitationRepo = $this->dm->getRepository(SmsInvitation::class);
        $prevInvitations = $invitationRepo->findDuplicate($policy, $mobile);
        if (count($prevInvitations) > 0) {
            throw new DuplicateInvitationException('Mobile was already invited to this policy');
        }

        $optOutRepo = $this->dm->getRepository(SmsOptOut::class);
        $optouts = $optOutRepo->findOptOut($mobile, SmsOptOut::OPTOUT_CAT_INVITATIONS);
        if (count($optouts) > 0) {
            return null;
        }

        if ($policy->getUser()->getMobileNumber() == $mobile) {
            throw new SelfInviteException('User can not invite themself');
        }

        $invitation = new SmsInvitation();
        $invitation->setMobile($mobile);
        $invitation->setPolicy($policy);
        $invitation->setName($name);
        $invitation->invite();
        $this->dm->persist($invitation);
        $this->dm->flush();

        $link = $this->getShortLink($invitation);
        $invitation->setLink($link);
        $this->dm->flush();

        $this->sendSms($invitation);
        $this->dm->flush();

        return $invitation;
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

        $message = \Swift_Message::newInstance()
            ->setSubject($subject)
            ->setFrom('hello@so-sure.com')
            ->setTo($to)
            ->setBody(
                $this->templating->render($htmlTemplate, ['invitation' => $invitation]),
                'text/html'
            )
            ->addPart(
                $this->templating->render($textTemplate, ['invitation' => $invitation]),
                'text/plain'
            );
        try {
            $this->mailer->send($message);
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
    protected function sendSms(SmsInvitation $invitation)
    {
        if ($this->debug) {
            return;
        }

        $message = $this->templating->render('AppBundle:Sms:invitation.txt.twig', ['invitation' => $invitation]);
        if ($this->sms->send($invitation->getMobile(), $message)) {
            $invitation->setStatus(SmsInvitation::STATUS_SENT);
        } else {
            $invitation->setStatus(SmsInvitation::STATUS_FAILED);
        }
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

        // If there was a concellation in the network, new connection should replace the cancelled connection
        $this->addConnection($inviteePolicy, $invitation->getInviter(), $inviterPolicy, $date);
        $this->addConnection($inviterPolicy, $invitation->getInvitee(), $inviteePolicy, $date);

        $invitation->setAccepted($date);

        // TODO: ensure transaction state for this one....
        $this->dm->flush();

        // Notify inviter of acceptance
        $this->sendEmail($invitation, self::TYPE_EMAIL_ACCEPT);
    }

    protected function addConnection(Policy $policy, User $linkedUser, Policy $linkedPolicy, \DateTime $date = null)
    {
        $connectionValue = $policy->getAllowedConnectionValue($date);
        // If there was a concellation in the network, new connection should replace the cancelled connection
        if ($replacementConnection = $policy->getUnreplacedConnectionCancelledPolicyInLast30Days($date)) {
            $connectionValue = $replacementConnection->getInitialValue();
            $replacementConnection->setReplacementUser($linkedUser);
        }
        $connection = new Connection();
        $connection->setUser($linkedUser);
        $connection->setLinkedPolicy($linkedPolicy);
        $connection->setValue($connectionValue);
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
        } elseif ($invitation instanceof SmsInvitation) {
            $this->sendSms($invitation);
            $invitation->reinvite();
            $this->dm->flush();
        } else {
            throw new \Exception('Unknown invitation type. Unable to reinvite.');
        }

        return true;
    }
}
