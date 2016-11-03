<?php

namespace AppBundle\Listener;

use AppBundle\Service\JudopayService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Event\PolicyEvent;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\SoSurePayment;
use AppBundle\Document\JudoPayment;

class RefundListener
{
    /** @var DocumentManager */
    protected $dm;

    /** @var JudopayService */
    protected $judopayService;

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $environment;

    /**
     * @param DocumentManager $dm
     * @param JudopayService  $judopayService
     * @param LoggerInterface $logger
     * @param string          $environment
     */
    public function __construct(
        DocumentManager $dm,
        JudopayService $judopayService,
        LoggerInterface $logger,
        $environment
    ) {
        $this->dm = $dm;
        $this->judopayService = $judopayService;
        $this->logger = $logger;
        $this->environment = $environment;
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();

        $payment = $policy->getLastSuccessfulPaymentCredit();
        $refundAmount = $policy->getRefundAmount($event->getDate());
        $refundCommissionAmount = $policy->getRefundCommissionAmount($event->getDate());
        $this->logger->info(sprintf('Processing refund %f (policy %s)', $refundAmount, $policy->getId()));
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

    /**
     * @param PolicyEvent $event
     */
    public function refundFreeNovPromo(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if (!in_array($policy->getPromoCode(), [Policy::PROMO_FREE_NOV, Policy::PROMO_LAUNCH_FREE_NOV])) {
            return;
        }

        $payment = $policy->getLastSuccessfulPaymentCredit();
        // Only run against JudoPayments
        if (!$payment instanceof JudoPayment) {
            return;
        }

        // Refund for Nov will break test cases
        if ($this->environment == "test") {
            return;
        }

        $refundAmount = $policy->getPremium()->getMonthlyPremiumPrice();
        $refundCommissionAmount = null;
        if ($policy->getPremiumPlan() == Policy::PLAN_MONTHLY) {
            $refundCommissionAmount = $payment->getTotalCommission();
        } elseif ($policy->getPremiumPlan() == Policy::PLAN_YEARLY) {
            $oneMonth = clone $policy->getStart();
            $oneMonth = $oneMonth->add(new \DateInterval('P1M'));
            $refundCommissionAmount = $policy->getProratedRefundCommissionAmount($oneMonth);
        }

        if ($refundAmount > $payment->getAmount()) {
            $this->logger->error(sprintf(
                'Manual processing required (policy %s), Free Nov Promo refund %f is more than last payment.',
                $policy->getId(),
                $refundAmount
            ));

            return;
        }
        $this->judopayService->refund($payment, $refundAmount, $refundCommissionAmount, 'free nov promo refund');
        $sosurePayment = SoSurePayment::duplicate($payment);
        $sosurePayment->setNotes('free nov promo paid by so-sure');
        $policy->addPayment($sosurePayment);
        $this->dm->flush();
    }
}
