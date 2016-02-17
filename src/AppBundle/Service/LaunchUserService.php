<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;

class LaunchUserService
{
    /** @var LoggerInterface */
    protected $logger;

    protected $dm;
    protected $mailchimp;

    /**
     * @param mixed            $doctrine
     * @param LoggerInterface  $logger
     * @param MailchimpService $mailchimp
     */
    public function __construct($doctrine, LoggerInterface $logger, MailchimpService $mailchimp)
    {
        $this->dm = $doctrine->getManager();
        $this->logger = $logger;
        $this->mailchimp = $mailchimp;
    }

    /**
     * @param User $user
     *
     * @return User
     */
    public function addUser(User $user)
    {
        $repo = $this->dm->getRepository(User::class);
        $addMailchimp = true;
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
            $addMailchimp = false;
        }

        $existingUser = $repo->findOneBy(['emailCanonical' => $user->getEmailCanonical()]);
        if (!$existingUser) {
            throw new \Exception('Failed to add');
        }

        if ($addMailchimp) {
            $this->mailchimp->subscribe($user->getEmail());
        }

        return $existingUser;
    }
}
