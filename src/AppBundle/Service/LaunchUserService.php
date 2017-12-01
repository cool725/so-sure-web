<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

class LaunchUserService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var MailchimpService */
    protected $mailchimp;

    /** @var MailerService */
    protected $mailer;
    protected $templating;
    protected $router;

    /** @var ShortLink */
    protected $shortLink;

    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param MailchimpService $mailchimp
     * @param MailerService    $mailer
     * @param                  $templating
     * @param                  $router
     * @param ShortLinkService $shortLink
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailchimpService $mailchimp,
        MailerService $mailer,
        $templating,
        $router,
        ShortLinkService $shortLink
    ) {
        $this->dm = $dm;
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
     * @return array 'user' & 'new'
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

        /**
         * Not necessary to email once we launch
        if ($userCreated || $resend) {
            $this->sendEmail($existingUser);
        }
        */
        \AppBundle\Classes\NoOp::ignore([$resend]);

        return ['user' => $existingUser, 'new' => $userCreated];
    }

    /**
     * Get link for a referral
     *
     * @param string $userId
     */
    public function getLink($userId)
    {
        return $this->router->generateUrl('homepage', ['referral' => $userId]);
    }

    /**
     * Get Short Link for a referral
     *
     * @param string $userId
     */
    public function getShortLink($userId)
    {
        $url = $this->getLink($userId);
        $referralUrl = $this->shortLink->addShortLink($url);

        return $referralUrl;
    }

    /**
     * Send user a pre-launch email
     *
     * @param User $user
     */
    public function sendEmail(User $user)
    {
        $referralUrl = $this->getShortLink($user->getId());
        $this->mailer->sendTemplate(
            'Welcome to so-sure',
            $user->getEmail(),
            'AppBundle:Email:preLaunch.html.twig',
            ['referral_url' => $referralUrl],
            'AppBundle:Email:preLaunch.txt.twig',
            ['referral_url' => $referralUrl]
        );
    }
}
