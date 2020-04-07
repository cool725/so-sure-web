<?php

namespace AppBundle\Listener;

use AppBundle\Service\ReferralService;
use Psr\Log\LoggerInterface;
use AppBundle\Event\PolicyEvent;

class ReferralListener
{
    /** @var ReferralService */
    protected $referralService;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param ReferralService $referralService
     * @param LoggerInterface $logger
     */
    public function __construct(
        ReferralService $referralService,
        LoggerInterface $logger
    ) {
        $this->referralService = $referralService;
        $this->logger = $logger;
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyRenewedEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        $this->referralService->processSleepingReferrals($policy);

    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        $this->referralService->cancelReferrals($policy);
    }
}
