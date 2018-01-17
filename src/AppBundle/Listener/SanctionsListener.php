<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\CompanyEvent;
use AppBundle\Service\MailerService;
use AppBundle\Service\SanctionsService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Snc\RedisBundle;
use Psr\Log\LoggerInterface;

class SanctionsListener
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var MailerService */
    protected $mailer;

    protected $sanctions;

    protected $redis;

    const SANCTIONS_LISTENER_REDIS_KEY = 'sanctions_email_queue';
    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param MailerService    $mailer
     * @param SanctionsService $sanctions
     * @param                  $redis
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer,
        SanctionsService $sanctions,
        $redis
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->sanctions = $sanctions;
        $this->redis = $redis;
    }

    /**
     * @param UserEvent $event
     */
    public function onUserCreatedEvent(UserEvent $event)
    {
        $user = $event->getUser();
        $matches = $this->sanctions->checkUser($user);
        if (count($matches) > 0) {
            $this->redis->rpush(
                self::SANCTIONS_LISTENER_REDIS_KEY,
                serialize(['user' => $user, 'matches' => $matches])
            );
        }
    }

    /**
     * @param CompanyEvent $event
     */
    public function onCompanyCreatedEvent(CompanyEvent $event)
    {
        $company = $event->getCompany();
        $matches = $this->sanctions->checkCompany($company);
        if (count($matches) > 0) {
            $this->redis->rpush(
                self::SANCTIONS_LISTENER_REDIS_KEY,
                serialize(['company' => $company, 'matches' => $matches])
            );
        }
    }
}

