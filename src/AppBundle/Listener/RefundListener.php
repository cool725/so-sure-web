<?php

namespace AppBundle\Listener;

use AppBundle\Service\JudopayService;
use Psr\Log\LoggerInterface;
use AppBundle\Event\PolicyEvent;
use AppBundle\Document\Policy;

class RefundListener
{
    /** @var JudopayService */
    protected $judopayService;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param JudopayService  $judopayService
     * @param LoggerInterface $logger
     */
    public function __construct(JudopayService $judopayService, LoggerInterface $logger)
    {
        $this->judopayService = $judopayService;
        $this->logger = $logger;
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if (!$policy->isCancelled() ||
            $policy->getCancelledReason() != Policy::CANCELLED_COOLOFF ||
            !$policy->isWithinCooloffPeriod()
        ) {
            return;
        }

        if (count($policy->getPayments()) != 1) {
            $this->logger->error(sprintf(
                'Unable to auto-refund policy %s as has 0 or more than 1 payments',
                $policy->getPolicyNumber()
            ));

            return;
        }

        $this->judopayService->refund($policy->getPayments()[0]);
    }
}
