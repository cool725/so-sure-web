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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

class InvitationService
{
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
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        \Swift_Mailer $mailer,
        $templating,
        $router,
        ShortLinkService $shortLink,
        SmsService $sms
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->templating = $templating;
        $this->router = $router->getRouter();
        $this->shortLink = $shortLink;
        $this->sms = $sms;
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

    public function email(Policy $policy, $email, $name = null)
    {
        $invitationRepo = $this->dm->getRepository(EmailInvitation::class);
        $prevInvitations = $invitationRepo->findDuplicate($policy, $email);
        if (count($prevInvitations) > 0) {
            throw new \InvalidArgumentException('Email was already invited to this policy');
        }

        $optOutRepo = $this->dm->getRepository(EmailOptOut::class);
        $optouts = $optOutRepo->findOptOut($email, EmailOptOut::OPTOUT_CAT_INVITATIONS);
        if (count($optouts) > 0) {
            return null;
        }

        if ($policy->getUser()->getEmailCanonical() == strtolower($email)) {
            throw new \Exception('User can not invite themself');
        }

        $invitation = new EmailInvitation();
        $invitation->setEmail($email);
        $invitation->setPolicy($policy);
        $invitation->setName($name);
        $this->dm->persist($invitation);
        $this->dm->flush();

        $link = $this->getShortLink($invitation);
        $invitation->setLink($link);
        $this->dm->flush();

        $this->sendEmail($invitation);

        return $invitation;
    }

    public function sms(Policy $policy, $mobile, $name = null)
    {
        $invitationRepo = $this->dm->getRepository(SmsInvitation::class);
        $prevInvitations = $invitationRepo->findDuplicate($policy, $mobile);
        if (count($prevInvitations) > 0) {
            throw new \InvalidArgumentException('Mobile was already invited to this policy');
        }

        $optOutRepo = $this->dm->getRepository(SmsOptOut::class);
        $optouts = $optOutRepo->findOptOut($mobile, SmsOptOut::OPTOUT_CAT_INVITATIONS);
        if (count($optouts) > 0) {
            return null;
        }

        if ($policy->getUser()->getMobileNumber() == $mobile) {
            throw new \Exception('User can not invite themself');
        }

        $invitation = new SmsInvitation();
        $invitation->setMobile($mobile);
        $invitation->setPolicy($policy);
        $invitation->setName($name);
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
     * @param EmailInvitation $invitation
     */
    public function sendEmail(EmailInvitation $invitation)
    {
        $message = \Swift_Message::newInstance()
            ->setSubject(sprintf('%s has invited you to so-sure', $invitation->getInviter()->getName()))
            ->setFrom('hello@so-sure.com')
            ->setTo($invitation->getEmail())
            ->setBody(
                $this->templating->render('AppBundle:Email:invitation.html.twig', ['invitation' => $invitation]),
                'text/html'
            )
            ->addPart(
                $this->templating->render('AppBundle:Email:invitation.txt.twig', ['invitation' => $invitation]),
                'text/plain'
            );
        if (!$this->debug) {
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
    }

    /**
     * Send an invitation sms
     *
     * @param SmsInvitation $invitation
     */
    public function sendSms(SmsInvitation $invitation)
    {
        $message = $this->templating->render('AppBundle:Sms:invitation.txt.twig', ['invitation' => $invitation]);
        if (!$this->debug) {
            if ($this->sms->send($invitation->getMobile(), $message)) {
                $invitation->setStatus(SmsInvitation::STATUS_SENT);
            } else {
                $invitation->setStatus(SmsInvitation::STATUS_FAILED);
            }
        }
    }

    public function accept(Invitation $invitation, Policy $inviteePolicy)
    {
        if ($invitation->isProcessed()) {
            throw new \Exception("Invitation has already been processed");
        }

        $inviterPolicy = $invitation->getPolicy();

        $connectionInviter = new Connection();
        $connectionInviter->setUser($invitation->getInviter());
        // connection value is based on that user's policy date
        $connectionInviter->setValue($inviterPolicy->getConnectionValue());
        // TODO: Validate connection with same user doesn't already exist
        $inviteePolicy->addConnection($connectionInviter);
        $inviteePolicy->updatePotValue();

        $connectionInvitee = new Connection();
        $connectionInvitee->setUser($invitation->getInvitee());
        // connection value is based on that user's policy date
        $connectionInvitee->setValue($inviteePolicy->getConnectionValue());
        $inviterPolicy->addConnection($connectionInvitee);
        $inviterPolicy->updatePotValue();

        $invitation->setAccepted(new \DateTime());

        // TODO: ensure transaction state for this one....
        $this->dm->flush();

        // TODO: notify inviter (push? email?)
    }

    public function reject(Invitation $invitation)
    {
        if ($invitation->isProcessed()) {
            throw new \Exception("Invitation has already been processed");
        }

        $invitation->setRejected(new \DateTime());
        $this->dm->flush();
        // TODO: notify inviter
    }

    public function cancel(Invitation $invitation)
    {
        if ($invitation->isProcessed()) {
            throw new \Exception("Invitation has already been processed");
        }

        $invitation->setCancelled(new \DateTime());
        $this->dm->flush();
        // TODO: notify invitee??
    }

    public function reinvite(Invitation $invitation)
    {
        if ($invitation->isProcessed()) {
            throw new \Exception("Invitation has already been processed");
        }

        if (!$invitation->canReinvite()) {
            return false;
        }

        if ($invitation instanceof EmailInvitation) {
            $this->sendEmail($invitation);
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
