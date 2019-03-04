<?php

namespace AppBundle\Listener;

use AppBundle\Event\InvitationEvent;
use AppBundle\Event\PaymentEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserPaymentEvent;
use AppBundle\Service\HubspotService;

/**
 * Waits for events to occur in our system and then updates the state of Hubspot to match.
 */
class HubspotListener
{
    /** @var HubspotService */
    protected $hubspotService;

    /**
     * Builds the listener.
     * @param HubspotService $hubspotService provides hubspotService functionality.
     */
    public function __construct(HubspotService $hubspotService)
    {
        $this->hubspotService = $hubspotService;
    }

    /**
     * Hubspot actions for when a user is created.
     * @param UserEvent $event is the event object representing the creation of the user.
     */
    public function onUserCreatedEvent(UserEvent $event)
    {
        $this->hubspotService->queueUpdateContact($event->getUser());
    }

    /**
     * Hubspot actions for when a user is updated.
     * @param UserEvent $event is the event object representing the user update.
     */
    public function onUserUpdatedEvent(UserEvent $event)
    {
        $this->hubspotService->queueUpdateContact($event->getUser());
    }

    /**
     * Hubspot actions for when a user has a failed payment.
     * @param UserPaymentEvent $event represents the failure.
     */
    public function onUserPaymentFailedEvent(UserPaymentEvent $event)
    {
        $this->hubspotService->queueUpdateContact($event->getUser());
        /*
        TODO: apparantly event is unused but do this anyway.
        $this->hubspotService->queueUpdateContact(
            $event->getUser(),
            HubspotService::QUEUE_EVENT_USER_PAYMENT_FAILED,
            ['reason' => $event->getReason()]
        );
        */
    }

    /**
     * Hubspot actions for when a reward pot is changed.
     * @param PolicyEvent $event is the event object representing the reward pot change.
     */
    public function onPolicyPotEvent(PolicyEvent $event)
    {
        if ($event->getPolicy()->getUser()->getHubspotId()) {
            $this->hubspotService->queueUpdateDeal($event->getPolicy());
        }
    }

    /**
     * Hubspot actions for when a policy is cancelled.
     * @param PolicyEvent $event is the event object representing the cancellation.
     */
    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        if ($event->getPolicy()->getUser()->getHubspotId()) {
            $this->hubspotService->queueUpdateDeal($event->getPolicy());
        }
    }

    /**
     * Hubspot actions for when a pending policy is renewed.
     * @param PolicyEvent $event represents the renewal.
     */
    public function onPolicyPendingRenewedEvent(PolicyEvent $event)
    {
        $this->hubspotService->queueUpdateContact($event->getPolicy());
    }

    /**
     * Hubspot actions for when policy is renewed.
     * @param PolicyEvent $event represents the renewal.
     */
    public function onPolicyRenewedEvent(PolicyEvent $event)
    {
        if ($event->getPolicy()->getUser()->getHubspotId()) {
            $this->hubspotService->queueUpdateDeal($event->getPolicy());
        }
    }

    /**
     * Hubspot actions for when a policy starts.
     * @param PolicyEvent $event represents the policy starting.
     */
    public function onPolicyStartEvent(PolicyEvent $event)
    {
        $this->hubspotService->queueUpdateContact($event->getPolicy());
    }

    /**
     * Hubspot actions for when a policy goes unpaid.
     * @param PolicyEvent $event represents the policy going unpaid.
     */
    public function onPolicyUnpaidEvent(PolicyEvent $event)
    {
        $this->hubspotService->queueUpdateDeal($event->getPolicy());
    }

    /**
     * Hubspot actions for when a policy is reactivated.
     * @param PolicyEvent $event represents the reactivation.
     */
    public function onPolicyReactivatedEvent(PolicyEvent $event)
    {
        $this->hubspotService->queueUpdateDeal($event->getPolicy());
    }

    /**
     * Hubspot actions for when an invitation is accepted.
     * @param InvitationEvent $event represents the acceptance.
     */
    public function onInvitationAcceptedEvent(InvitationEvent $event)
    {
        $this->hubspotService->queueUpdateContact($event->getInvitation()->getInviter());
        $this->hubspotService->queueUpdateContact($event->getInvitation()->getInvitee());
    }

    /**
     * Hubspot actions for when a payment has been successful.
     * @param PaymentEvent $event represents the payment being successful.
     */
    public function onPaymentSuccessEvent(PaymentEvent $event)
    {
        $this->hubspotService->queueUpdateContact($event->getPayment()->getPolicy()->getUser());
    }

    /**
     * Hubspot actions for when a payment fails.
     * @param PaymentEvent $event represents the payment failure.
     */
    public function onPaymentFailedEvent(PaymentEvent $event)
    {
        $this->hubspotService->queueUpdateContact($event->getPayment()->getPolicy()->getUser());
    }
}
