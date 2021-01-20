<?php

namespace AppBundle\Service;

use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Policy;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Document\DateTrait;
use AppBundle\Exception\CannotRefundException;
use AppBundle\Exception\DuplicateImeiException;
use AppBundle\Exception\LostStolenImeiException;
use AppBundle\Exception\InvalidImeiException;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\PolicyEvent;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Templating\EngineInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for doing upgrades. May seem like overkill to have a whole service for this one thing, but the dependencies
 * required are quite hard to come by.
 */
class UpgradeService
{
    /** @var DocumentManager $dm */
    private $dm;

    /** @var BacsService $bacsService */
    private $bacsService;

    /** @var CheckoutService $checkoutService */
    private $checkoutService;

    /** @var PolicyService $policyService */
    private $policyService;

    /** @var ReceperioService $imeiValidator */
    private $imeiValidator;

    /** @var EventDispatcherInterface */
    private $dispatcher;

    /** @var LoggerInterface */
    private $logger;

    /**
     * Injects the dependencies.
     * @param DocumentManager          $dm              is the document manager for loading and saving stuff to the db.
     * @param BacsService              $bacsService     is used to schedule bacs payments.
     * @param CheckoutService          $checkoutService is used to create checkout payments.
     * @param PolicyService            $policyService   is used to help set up policies.
     * @param ReceperioService         $imeiValidator   is used to check that the IMEIs that are sent are all good.
     * @param EventDispatcherInterface $dispatcher      is used for dispatching events.
     * @param LoggerInterface          $logger          is used to log developer messages.
     */
    public function __construct(
        DocumentManager $dm,
        BacsService $bacsService,
        CheckoutService $checkoutService,
        PolicyService $policyService,
        ReceperioService $imeiValidator,
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger
    ) {
        $this->dm = $dm;
        $this->bacsService = $bacsService;
        $this->checkoutService = $checkoutService;
        $this->policyService = $policyService;
        $this->imeiValidator = $imeiValidator;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    /**
     * Upgrades a policy to be referencing a new phone and have a new price and payment schedule.
     * @param HelvetiaPhonePolicy $policy    is the policy. No other kind of policy can be upgraded currently.
     * @param Phone               $phone     is the model of phone they are upgrading to.
     * @param string              $imei      is the imei of the phone they are upgrading to.
     * @param string|null         $serial    is the serial number of the phone they are upgrading to if any.
     * @param \DateTime           $date      is the date and time that the upgrade is taking effect.
     * @param PhonePremium        $premium   is the premium that the policy will now have.
     * @param string              $phoneData is data about the phone used to make the upgrade from the app.
     * @throws \RuntimeException         when something goes wrong. If something goes wrong no changes should have been
     *                                   persisted. This may happen because their yearly payment could not be taken
     *                                   synchronously.
     * @throws \InvalidArgumentException if the policy in question has a monetary claim.
     * @throws InvalidImeiException      if the given imei is invalid.
     * @throws DuplicateImeiException    if you try to upgrade to a phone already in the system.
     * @throws LostStolenImeiException   if the you try to upgrade to a phone we have recorded as lost or stolen.
     * @throws CannotRefundException     if the user cannot be given a refund and thus the upgrade is not going to
     *                                   work.
     */
    public function upgrade(HelvetiaPhonePolicy $policy, $phone, $imei, $serial, $date, $premium, $phoneData = null)
    {
        if ($policy->hasMonetaryClaimed(true) || $policy->hasOpenClaim()) {
            throw new \InvalidArgumentException(sprintf(
                'Policy %s cannot self upgrade due to monetary claim',
                $policy->getId()
            ));
        }
        if (!$this->imeiValidator->isImei($imei)) {
            throw new InvalidImeiException();
        }
        if ($this->imeiValidator->isLostImei($imei)) {
            throw new LostStolenImeiException();
        }
        if ($this->imeiValidator->isDuplicatePolicyImei($imei)) {
            throw new DuplicateImeiException();
        }
        $iteration = $policy->getCurrentIteration();
        $iteration->setEnd((clone $date)->sub(new \DateInterval('PT5S')));
        $policy->addPreviousIteration($iteration);
        $policy->setPhone($phone);
        $policy->setImei($imei);
        $policy->setSerialNumber($serial);
        $policy->setPremium($premium);
        $policy->setPhoneData($phoneData);
        // Create new payment schedule or payments depending on the policy schedule.
        $futurePayments = $policy->countFutureInvoiceSchedule($policy->getCurrentIterationStart());
        $amount = $policy->getUpgradedYearlyPrice();
        if ($futurePayments > 0 && $amount > 0) {
            $this->policyService->regenerateScheduledPayments($policy, $date, $date);
        } elseif ($amount != 0) {
            $paymentMethod = $policy->getPaymentMethod();
            if ($paymentMethod->getType() == PaymentMethod::TYPE_CHECKOUT) {
                try {
                    if ($amount > 0) {
                        $payment = $this->checkoutService->tokenPay($policy, $amount, 'upgrade', false);
                        $policy->setCommission($payment);
                    } else {
                        /** @var CheckoutPayment $refundPayment */
                        $refundPayment = $policy->findPaymentForRefund($amount);
                        if ($refundPayment) {
                            $proportion = $amount / $refundPayment->getAmount();
                            $coverholderCommission = $proportion * $refundPayment->getCoverholderCommission();
                            $brokerCommission = $proportion * $refundPayment->getBrokerCommission();
                            $this->checkoutService->refund(
                                $refundPayment,
                                0 - $amount,
                                0 - $coverholderCommission,
                                0 - $brokerCommission
                            );
                        } else {
                            throw new CannotRefundException('Cannot refund. No eligible payments');
                        }
                    }
                } catch (\Exception $e) {
                    $this->dm->detach($policy);
                    $this->logger->error(sprintf(
                        'Yearly upgrade payment failure for %s. Please verify.',
                        $policy->getId()
                    ));
                    throw new \RuntimeException('Yearly Payment Failed');
                }
            } elseif ($paymentMethod->getType() == PaymentMethod::TYPE_BACS) {
                $this->bacsService->scheduleBacsPayment(
                    $policy,
                    $amount,
                    ScheduledPayment::TYPE_USER_WEB,
                    'upgrade'
                );
            } else {
                throw new \RuntimeException(sprintf(
                    'Policy %s has neither a bacs or checkout payment method so no upgrade can be performed.',
                    $policy->getId()
                ));
            }
        }
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_UPGRADED);
        $this->policyService->clearRescheduledPayments($policy);
        $this->dm->persist($policy);
        $this->dm->flush();
        // Dispatch an event.
        $this->policyService->queueMessage($policy);
        $this->dispatcher->dispatch(PolicyEvent::EVENT_UPGRADED, new PolicyEvent($policy));
    }
}
