<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class LaunchUserService
{
    /** @var LoggerInterface */
    protected $logger;

    protected $dm;

    /** @var MailchimpService */
    protected $mailchimp;

    /** @var \Swift_Mailer */
    protected $mailer;
    protected $templating;
    protected $router;

    /** @var ShortLink */
    protected $shortLink;

    /**
     * @param mixed            $doctrine
     * @param LoggerInterface  $logger
     * @param MailchimpService $mailchimp
     * @param \Swift_Mailer    $mailer
     * @param                  $templating
     * @param                  $router
     * @param ShortLinkService $shortLink
     */
    public function __construct(
        $doctrine,
        LoggerInterface $logger,
        MailchimpService $mailchimp,
        \Swift_Mailer $mailer,
        $templating,
        $router,
        ShortLinkService $shortLink
    ) {
        $this->dm = $doctrine->getManager();
        $this->logger = $logger;
        $this->mailchimp = $mailchimp;
        $this->mailer = $mailer;
        $this->templating = $templating;
        $this->router = $router;
        $this->shortLink = $shortLink;
    }

    /**
     * @param User    $user
     * @param boolean $resend Resend the launch email even if user exists
     *
     * @return User
     */
    public function addUser(User $user, $resend = false)
    {
        $repo = $this->dm->getRepository(User::class);
        $userCreated = true;
        try {
            if ($user->getReferralId() && !$user->getReferred()) {
                $referred = $repo->find($user->getReferralId());
                $referred->addReferral($user);
            }
            $user->setUsername(strtolower($user->getEmail()));
            $this->dm->persist($user);
            $this->dm->flush();
        } catch (\Exception $e) {
            // Ignore - most likely existing user
            $this->logger->error($e->getMessage());
            $userCreated = false;
        }

        $existingUser = $repo->findOneBy(['emailCanonical' => $user->getEmailCanonical()]);
        if (!$existingUser) {
            throw new \Exception('Failed to add');
        }

        if ($userCreated) {
            $this->mailchimp->subscribe($user->getEmail());
        }

        if ($userCreated || $resend) {
            $this->sendEmail($existingUser);
        }

        return $existingUser;
    }

    /**
     * Send user a pre-launch email
     *
     * @param User $user
     */
    public function sendEmail(User $user)
    {
        $url = $this->router->generate('homepage', ['referral' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        $referralUrl = $this->shortLink->addShortLink($url);
        $message = \Swift_Message::newInstance()
            ->setSubject('Welcome to so-sure')
            ->setFrom('hello@so-sure.com')
            ->setTo($user->getEmail())
            ->setBody(
                $this->templating->render('AppBundle:Email:preLaunch.html.twig', ['referral_url' => $referralUrl]),
                'text/html'
            )
            ->addPart(
                $this->templating->render('AppBundle:Email:preLaunch.txt.twig', ['referral_url' => $referralUrl]),
                'text/plain'
            );
        $this->mailer->send($message);
    }
}
