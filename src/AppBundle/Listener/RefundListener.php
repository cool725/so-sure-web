<?php

namespace AppBundle\Listener;

use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Service\BacsService;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\JudopayService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\Salva;
use AppBundle\Event\PolicyEvent;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Cashback;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\Payment\PolicyDiscountPayment;
use AppBundle\Document\Payment\PolicyDiscountRefundPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\CurrencyTrait;

class RefundListener
{
    use CurrencyTrait;

    /** @var DocumentManager */
    protected $dm;

    /** @var CheckoutService */
    protected $checkoutService;

    /** @var JudopayService */
    protected $judopayService;

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $environment;

    /** @var BacsService */
    protected $bacsService;

    /**
     * @param DocumentManager $dm
     * @param CheckoutService $checkoutService
     * @param LoggerInterface $logger
     * @param string          $environment
     * @param BacsService     $bacsService
     */
    public function __construct(
        DocumentManager $dm,
        CheckoutService $checkoutService,
        JudopayService $judopayService,
        LoggerInterface $logger,
        $environment,
        BacsService $bacsService
    ) {
        $this->dm = $dm;
        $this->checkoutService = $checkoutService;
        $this->judopayService = $judopayService;
        $this->logger = $logger;
        $this->environment = $environment;
        $this->bacsService = $bacsService;
    }

    /**
     * @param PolicyEvent $event
     * @throws \Exception
     */
    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();

        if (!$policy->isPolicy()) {
            $this->logger->error(sprintf(
                'Cancelling non-policy %s. Manually refund any payments and cancel with Salva',
                $policy->getId()
            ));
            return;
        }

        // Cooloff cancellations should refund any so-sure payments
        if ($policy->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            $payments = $policy->getPayments();
            $total = Payment::sumPayments($payments, false, SoSurePayment::class);

            if (!$this->areEqualToTwoDp(0, $total['total'])) {
                $sosurePayment = SoSurePayment::init(Payment::SOURCE_SYSTEM);
                $sosurePayment->setAmount(0 - $total['total']);
                $sosurePayment->setTotalCommission(0 - $total['totalCommission']);
                $sosurePayment->setNotes(sprintf(
                    'cooloff cancellation refund of promo %s paid by so-sure',
                    $policy->getPromoCode()
                ));
                $policy->addPayment($sosurePayment);
                $this->dm->flush();
            }
        }

        if ($policy->isRefundAllowed() && $policy->hasPolicyDiscountPresent()) {
            $totalDiscount = Payment::sumPayments($policy->getPayments(), false, PolicyDiscountPayment::class);
            $total = $totalDiscount['total'];
            // Cooloff should retain full amount of discount
            if ($policy->getCancelledReason() != Policy::CANCELLED_COOLOFF) {
                $total = $this->toTwoDp($total - ($total * $policy->getProrataMultiplier($event->getDate())));
            }
            if ($this->greaterThanZero($total)) {
                if ($policy->hasCashback()) {
                    throw new \Exception(sprintf(
                        'Unable to cancel w/cashback as already has cashback. %s %0.2f',
                        $policy->getId(),
                        $total
                    ));
                }
                $notes = sprintf(
                    'initial discount £%0.2f. policy is now cancelled and refund due to customer',
                    $total
                );
                // Offset the policy discount with a refund
                $policyDiscountRefundPayment = new PolicyDiscountRefundPayment();
                $policyDiscountRefundPayment->setAmount(0 - $total);
                $policyDiscountRefundPayment->setDate($event->getDate());
                $policyDiscountRefundPayment->setNotes($notes);
                $policy->addPayment($policyDiscountRefundPayment);

                // and convert to cashback
                $cashback = new Cashback();
                $cashback->setDate(\DateTime::createFromFormat('U', time()));
                $cashback->setStatus(Cashback::STATUS_MISSING);
                $cashback->setAmount($total);
                $policy->setCashback($cashback);
            }
        }

        $payment = $policy->getLastSuccessfulUserPaymentCredit();
        $refundAmount = $policy->getRefundAmount();
        $refundCommissionAmount = $policy->getRefundCommissionAmount();
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
            try {
                $notes = sprintf('cancelled %s', $policy->getCancelledReason());
                if ($payment instanceof CheckoutPayment) {
                    $this->checkoutService->refund($payment, $refundAmount, $refundCommissionAmount, $notes);
                } elseif ($payment instanceof JudoPayment) {
                    $this->judopayService->refund($payment, $refundAmount, $refundCommissionAmount, $notes);
                } elseif ($payment instanceof BacsPayment) {
                    // Refund is a negative payment
                    $this->bacsService->scheduleBacsPayment(
                        $policy,
                        0 - $refundAmount,
                        ScheduledPayment::TYPE_REFUND,
                        $notes
                    );
                } else {
                    throw new \Exception(sprintf('Unable to refund %s payments', get_class($payment)));
                }
            } catch (\Exception $e) {
                $this->logger->error(
                    sprintf(
                        'Failed to refund policy %s for %0.2f. Fix issue, then manually cancel at salva.',
                        $policy->getId(),
                        $refundAmount
                    ),
                    ['exception' => $e]
                );
                return;
            }
        } elseif ($policy->hasPendingClosedClaimed()) {
            $refundAmount = $policy->getRefundAmount(true);
            $refundCommissionAmount = $policy->getRefundCommissionAmount(true);
            $this->logger->warning(sprintf(
                'Skipping refund of %f (commission %s) for policy %s as claim was auto-closed',
                $refundAmount,
                $refundCommissionAmount,
                $policy->getId()
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
    public function refundFreeMonthPromo(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if (!in_array($policy->getPromoCode(), [
            Policy::PROMO_FREE_NOV,
            Policy::PROMO_LAUNCH_FREE_NOV,
            Policy::PROMO_FREE_DEC_2016,
        ])) {
            return;
        }

        $payment = $policy->getLastSuccessfulUserPaymentCredit();
        // Only run against CheckoutPayment
        if (!$payment instanceof CheckoutPayment) {
            return;
        }

        // Refund for Nov will break test cases
        if ($this->environment == "test") {
            return;
        }

        $refundAmount = $policy->getPremium()->getMonthlyPremiumPrice();
        $refundCommissionAmount = Salva::MONTHLY_TOTAL_COMMISSION;

        if ($refundAmount > $payment->getAmount()) {
            $this->logger->error(sprintf(
                'Manual processing required (policy %s), Promo %s refund %f is more than last payment.',
                $policy->getId(),
                $policy->getPromoCode(),
                $refundAmount
            ));

            return;
        }
        try {
            if ($payment instanceof CheckoutPayment) {
                $this->checkoutService->refund(
                    $payment,
                    $refundAmount,
                    $refundCommissionAmount,
                    sprintf('promo %s refund', $policy->getPromoCode()),
                    Payment::SOURCE_SYSTEM
                );
            } elseif ($payment instanceof JudoPayment) {
                $this->judopayService->refund(
                    $payment,
                    $refundAmount,
                    $refundCommissionAmount,
                    sprintf('promo %s refund', $policy->getPromoCode()),
                    Payment::SOURCE_SYSTEM
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf(
                    'Failed to refund free month promo on policy %s for %0.2f.',
                    $policy->getId(),
                    $refundAmount
                ),
                ['exception' => $e]
            );
            return;
        }

        $sosurePayment = SoSurePayment::init(Payment::SOURCE_SYSTEM);
        $sosurePayment->setAmount($refundAmount);
        $sosurePayment->setTotalCommission($refundCommissionAmount);
        $sosurePayment->setNotes(sprintf('promo %s paid by so-sure', $policy->getPromoCode()));
        $policy->addPayment($sosurePayment);
        $this->dm->flush();
    }
}
