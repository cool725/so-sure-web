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

        // Just in case - make sure we don't refund for non-cancelled policies
        // or policies that shouldn't have been cancelled in the first place
        if (!$policy->isCancelled() || !$policy->canCancel($policy->getCancelledReason())) {
            return;
        }

        if ($policy->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            $this->cooloffCancellation($policy);
        } elseif ($policy->getCancelledReason() == CANCELLED_USER_REQUESTED) {
            $this->userRequestedCancellation($policy);
        } elseif ($policy->getCancelledReason() == Policy::CANCELLED_WRECKAGE ||
            $policy->getCancelledReason() == Policy::CANCELLED_DISPOSSESSION) {
            $this->wreckageDispossessionCancellation($policy);
        } else {
            // CANCELLED_UNPAID, CANCELLED_ACTUAL_FRAUD, CANCELLED_SUSPECTED_FRAUD never receive refunds
            // CANCELLED_BADRISK - no longer in use
        }
    }

    private function cooloffCancellation($policy)
    {
        if (count($policy->getSuccessfulPaymentCredits()) != 1) {
            $this->logger->error(sprintf(
                'Unable to auto-refund policy %s as has 0 or more than 1 payments',
                $policy->getPolicyNumber()
            ));

            return;
        }

        $paymentToRefund = $policy->getLastSuccessfulPaymentCredit();
        if (!$policy->validateRefundAmount($paymentToRefund)) {
            $this->logger->error(sprintf(
                'Unable to auto-refund policy %s as refund amount (%f) does not match premium amount (%f)',
                $policy->getPolicyNumber(),
                $paymentToRefund->getAmount(),
                $policy->getPremiumInstallmentPrice()
            ));

            return;
        }

        // last should be same as first given above
        $this->judopayService->refund($paymentToRefund);
    }

    private function userRequestedCancellation($policy)
    {
        // user has 30 days from when they requested cancellation
        // however, as we don't easily have a scheduled cancellation
        // we will start with a manual cancellation that should be done
        // 30 days after they requested, such that the cancellation will be immediate
        // at that point
        // as we're doing that way, they will not be due a refund
        return;
    }

    private function wreckageDispossessionCancellation($policy)
    {
        // TODO: Annual
        $paymentToRefund = $policy->getLastSuccessfulPaymentCredit();
        if (!$policy->validateRefundAmount($paymentToRefund)) {
            $this->logger->error(sprintf(
                'Unable to auto-refund policy %s as refund amount (%f) does not match premium amount (%f)',
                $policy->getPolicyNumber(),
                $paymentToRefund->getAmount(),
                $policy->getPremiumInstallmentPrice()
            ));

            return;
        }

        // last should be same as first given above
        $this->judopayService->refund($paymentToRefund);
    }
}
