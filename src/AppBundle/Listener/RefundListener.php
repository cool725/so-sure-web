<?php

namespace AppBundle\Listener;

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
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\CurrencyTrait;

class RefundListener
{
    use CurrencyTrait;

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
                $cashback->setDate(new \DateTime());
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
                if ($payment instanceof JudoPayment) {
                    $this->judopayService->refund($payment, $refundAmount, $refundCommissionAmount, $notes);
                } elseif ($payment instanceof BacsPayment) {
                    // Refund is a negative payment
                    $refund = new BacsPayment();
                    $refund->setAmount(0 - $refundAmount);
                    $refund->setNotes($notes);
                    $refund->setSource(Payment::SOURCE_SYSTEM);
                    $refund->setRefundTotalCommission($refundCommissionAmount);
                    if ($policy->hasManualBacsPayment()) {
                        $refund->setManual(true);
                    }
                    $refund->setStatus(BacsPayment::STATUS_PENDING);
                    // TODO: we need to have the refund be successful in order to have the correct
                    // amount for the policy to send to salva. This is not ideal as its not a successful payment
                    $refund->setSuccess(true);
                    $payment->getPolicy()->addPayment($refund);
                    $this->dm->persist($refund);
                    $this->dm->flush(null, array('w' => 'majority', 'j' => true));
                    $this->logger->warning(sprintf('bacs refund due - id %s', $refund->getId()));
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
        // Only run against JudoPayments
        if (!$payment instanceof JudoPayment) {
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
        $this->judopayService->refund(
            $payment,
            $refundAmount,
            $refundCommissionAmount,
            sprintf('promo %s refund', $policy->getPromoCode()),
            Payment::SOURCE_SYSTEM
        );
        $sosurePayment = SoSurePayment::init(Payment::SOURCE_SYSTEM);
        $sosurePayment->setAmount($refundAmount);
        $sosurePayment->setTotalCommission($refundCommissionAmount);
        $sosurePayment->setNotes(sprintf('promo %s paid by so-sure', $policy->getPromoCode()));
        $policy->addPayment($sosurePayment);
        $this->dm->flush();
    }
}
