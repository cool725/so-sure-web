<?php

namespace AppBundle\Listener;

use App\Hubspot\HubspotData;
use AppBundle\Event\InvitationEvent;
use AppBundle\Event\PaymentEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserPaymentEvent;
use AppBundle\Service\HubspotService;

/**
 * @todo wrap more queue's with `isChanged()`, to avoid sending the same data.
 */
class HubspotListener
{
    /** @var HubspotService */
    protected $hubspot;
    /** @var \App\Hubspot\HubspotData */
    private $hubspotData;

    public function __construct(HubspotService $hubspot, HubspotData $hubspotData)
    {
        $this->hubspot = $hubspot;
        $this->hubspotData = $hubspotData;
    }

    public function onUserCreatedEvent(UserEvent $event)
    {
        $this->hubspot->queueContact($event->getUser());
    }

    public function onUserUpdatedEvent(UserEvent $event)
    {
        $this->hubspot->queueContact($event->getUser());
    }

    public function onPolicyPotEvent(PolicyEvent $event)
    {
        $this->hubspot->queueContact($event->getPolicy()->getUser());
        // TODO: Trigger hubspot event
    }

    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $this->hubspot->queueContact($event->getPolicy()->getUser());
//        $this->hubspot->queuePolicy($event->getPolicy(), HubspotService::QUEUE_EVENT_POLICY_CANCELLED);
    }

    public function onPolicyPendingRenewedEvent(PolicyEvent $event)
    {
        $this->hubspot->queueContact($event->getPolicy()->getUser());
        //$this->hubspot->queuePolicy($event->getPolicy(), HubspotService::QUEUE_EVENT_POLICY_PENDING_RENEWAL);
    }

    public function onPolicyRenewedEvent(PolicyEvent $event)
    {
        $this->hubspot->queueContact($event->getPolicy()->getUser());
        //$this->hubspot->queuePolicy($event->getPolicy(), HubspotService::QUEUE_EVENT_POLICY_RENEWED);
    }

    public function onPolicyStartEvent(PolicyEvent $event)
    {
        $this->hubspot->queueContact($event->getPolicy()->getUser());
        //$this->hubspot->queuePolicy($event->getPolicy(), HubspotService::QUEUE_EVENT_POLICY_START);

        // Eventually we want to migrate users to the policy started event
        // However, this will impact on users in the connection campaign, and so
        // send both created & started events for now until we can migrate over
        //$this->hubspot->queuePolicy($event->getPolicy(), HubspotService::QUEUE_EVENT_POLICY_CREATED);
    }

    public function onPolicyUnpaidEvent(PolicyEvent $event)
    {
        $this->hubspot->queueContact($event->getPolicy()->getUser());
    }

    public function onPolicyReactivatedEvent(PolicyEvent $event)
    {
        $this->hubspot->queueContact($event->getPolicy()->getUser());
    }

    public function onInvitationAcceptedEvent(InvitationEvent $event)
    {
        // Invitation accepted is a connection, so update both inviter & invitee
        $this->hubspot->queueContact($event->getInvitation()->getInviter());
        $this->hubspot->queueContact($event->getInvitation()->getInvitee());
    }

    public function onPaymentSuccessEvent(PaymentEvent $event)
    {
        // user record needs to be updated to ensure that the paid state is set correctly
        $this->hubspot->queueContact($event->getPayment()->getPolicy()->getUser());
        //$this->hubspot->queuePayment($event->getPayment(), HubspotService::QUEUE_EVENT_PAYMENT_SUCCESS);
    }

    public function onPaymentFailedEvent(PaymentEvent $event)
    {
        // user record needs to be updated to ensure that the paid state is set correctly
        $this->hubspot->queueContact($event->getPayment()->getPolicy()->getUser());
        //$this->hubspot->queuePayment($event->getPayment(), HubspotService::QUEUE_EVENT_PAYMENT_FAILED);
    }

    public function onPaymentFirstProblemEvent(PaymentEvent $event)
    {
        // We have a few new properties on the user that are required for the payment first problem
        // Resync user for now to ensure everything is present.
        // Eventually can be removed if all users are re-synced or if enough time has elapsed (1 year?)
        $this->hubspot->queueContact($event->getPayment()->getPolicy()->getUser());
        //$this->hubspot->queuePayment($event->getPayment(), HubspotService::QUEUE_EVENT_PAYMENT_FIRST_PROBLEM);
    }

    public function onUserPaymentFailedEvent(UserPaymentEvent $event)
    {
        /*
        TODO: this is unimplemented as far as I can see.
        $this->hubspot->queueContact(
            $event->getUser(),
            HubspotService::QUEUE_EVENT_USER_PAYMENT_FAILED,
            ['reason' => $event->getReason()]
        );
        */
    }
}
