<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use AppBundle\Document\EmailInvitation;
use AppBundle\Document\Invitation;
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

    public function email(User $inviter, $email, $name = null)
    {
        // TODO: Validate its not a re-invite
        // TODO: Validate the user hasn't requested an opt out

        $invitation = new EmailInvitation();
        $invitation->setEmail($email);
        $invitation->setInviter($inviter);
        $invitation->setName($name);
        $this->dm->persist($invitation);
        $this->dm->flush();

        $link = $this->getShortLink($invitation);
        $invitation->setLink($link);
        $this->dm->flush();

        $this->sendEmail($invitation);

        return $invitation;
    }

    /**
     * Send an invitation email
     *
     * @param EmailInvitation $invitation
     */
    public function sendEmail(EmailInvitation $invitation)
    {
        $referralUrl = $this->getShortLink($invitation);
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
        $this->mailer->send($message);
    }
}
