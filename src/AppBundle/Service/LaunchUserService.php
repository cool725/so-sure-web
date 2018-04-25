<?php
namespace AppBundle\Service;

use AppBundle\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Templating\EngineInterface;

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

    /** @var EngineInterface */
    protected $templating;

    /** @var RouterService */
    protected $router;

    /** @var ShortLinkService */
    protected $shortLink;

    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param MailchimpService $mailchimp
     * @param MailerService    $mailer
     * @param EngineInterface  $templating
     * @param RouterService    $router
     * @param ShortLinkService $shortLink
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailchimpService $mailchimp,
        MailerService $mailer,
        EngineInterface $templating,
        RouterService $router,
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
        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);
        $userCreated = true;
        try {
            if ($user->getReferralId() && !$user->getReferred()) {
                /** @var User $referred */
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
}
