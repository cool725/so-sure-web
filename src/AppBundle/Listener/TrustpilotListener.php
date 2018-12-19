<?php

namespace AppBundle\Listener;

use AppBundle\Service\PolicyService;
use AppBundle\Event\PolicyEvent;
use Psr\Log\LoggerInterface;
use AppBundle\Event\ConnectionEvent;
use AppBundle\Document\Policy;
use AppBundle\Service\MailerService;

class TrustpilotListener
{
    /** @var MailerService */
    protected $mailerService;

    /**
     * @param MailerService $mailerService
     */
    public function __construct(MailerService $mailerService)
    {
        $this->mailerService = $mailerService;
    }

    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        $this->mailerService->trustpilot($policy, MailerService::TRUSTPILOT_PURCHASE);
    }
}
