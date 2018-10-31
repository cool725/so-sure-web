<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use AppBundle\Event\PaymentEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\UserPaymentEvent;
use AppBundle\Service\MixpanelService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

class MixpanelListener
{
    /** @var MixpanelService */
    protected $mixpanel;

    /**
     * @param MixpanelService $mixpanel
     */
    public function __construct(MixpanelService $mixpanel)
    {
        $this->mixpanel = $mixpanel;
    }

    public function onPaymentSuccessEvent(PaymentEvent $event)
    {
        $payment = $event->getPayment();
        $this->mixpanel->queueTrackWithUser($payment->getUser(), MixpanelService::EVENT_PAYMENT, [
            'Amount' => $payment->getAmount(),
            'Policy Id' => $payment->getPolicy()->getId(),
        ]);
    }

    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        $source = 'Unknown';
        if ($payment = $policy->getLastSuccessfulUserPaymentCredit()) {
            $source = $payment->getSourceForClaims();
        }
        $this->mixpanel->queueTrackWithUser($policy->getUser(), MixpanelService::EVENT_PURCHASE_POLICY, [
            'Payment Option' => $policy->getPremiumPlan(),
            'Policy Id' => $policy->getId(),
            'Payment Source' => $source,
            'Payment Type' => $policy->getType()
        ]);
    }

    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        $this->mixpanel->queueTrackWithUser($policy->getUser(), MixpanelService::EVENT_CANCEL_POLICY, [
            'Cancellation Reason' => $policy->getCancelledReason(),
            'Policy Id' => $policy->getId(),
        ]);
    }

    public function onPolicyRenewedEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        $cashback = 'N/A';
        if ($policy->getPotValue() > 0) {
            if ($policy->getCashback()) {
                $cashback = 'Yes';
            } else {
                $cashback = 'No';
            }
        }

        $this->mixpanel->queueTrackWithUser($policy->getUser(), MixpanelService::EVENT_RENEW, [
            'Cashback' => $cashback,
            'Policy Id' => $policy->getId(),
        ]);
    }

    public function onPolicyDeclineRenewedEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        $cashback = 'N/A';
        if ($policy->getPotValue() > 0) {
            if ($policy->getCashback()) {
                $cashback = 'Yes';
            } else {
                $cashback = 'No';
            }
        }

        $this->mixpanel->queueTrackWithUser($policy->getUser(), MixpanelService::EVENT_DECLINE_RENEW, [
            'Cashback' => $cashback,
            'Policy Id' => $policy->getId(),
        ]);
    }

    public function onPolicyCashbackEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();

        $this->mixpanel->queueTrackWithUser($policy->getUser(), MixpanelService::EVENT_CASHBACK, [
            'Policy Id' => $policy->getId(),
            'Renewed' => $policy->isRenewed() ? 'Yes' : 'No',
            'Amount' => $policy->getCashback() ? $policy->getCashback()->getAmount() : 0,
        ]);
    }
}
