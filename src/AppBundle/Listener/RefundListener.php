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

        $payment = $policy->getLastSuccessfulPaymentCredit();
        $refundAmount = $policy->getRefundAmount();
        if ($refundAmount > 0) {
            $this->judopayService->refund($payment, $refundAmount);
        }
    }
}
