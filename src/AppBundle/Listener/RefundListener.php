<?php

namespace AppBundle\Listener;

use AppBundle\Service\JudopayService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Event\PolicyEvent;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;

class RefundListener
{
    /** @var DocumentManager */
    protected $dm;

    /** @var JudopayService */
    protected $judopayService;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param DocumentManager $dm
     * @param JudopayService  $judopayService
     * @param LoggerInterface $logger
     */
    public function __construct(DocumentManager $dm, JudopayService $judopayService, LoggerInterface $logger)
    {
        $this->dm = $dm;
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
        $refundCommissionAmount = $policy->getRefundCommissionAmount();
        if ($refundAmount > 0) {
            if ($refundAmount > $payment->getAmount()) {
                $this->logger->error(sprintf(
                    'For policy %s, refund owed %f is greater than last payment received. Manual processing required.',
                    $policy->getId(),
                    $refundAmount
                ));

                return;
            }
            $this->judopayService->refund($payment, $refundAmount, $refundCommissionAmount, sprintf(
                'cancelled %s',
                $policy->getCancelledReason()
            ));
        }

        if ($policy instanceof SalvaPhonePolicy) {
            // If refund was required, it's now finished (or exception thrown above, so skipped here)
            // Its now safe to allow the salva policy to be cancelled
            $policy->setSalvaStatus(SalvaPhonePolicy::SALVA_STATUS_PENDING_CANCELLED);
            $this->dm->flush();
        }
    }
}
