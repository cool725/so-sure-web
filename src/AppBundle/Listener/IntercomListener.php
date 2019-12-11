<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use AppBundle\Event\LeadEvent;
use AppBundle\Event\PicsureEvent;
use AppBundle\Event\UserEvent;
use AppBundle\Event\ClaimEvent;
use AppBundle\Event\ConnectionEvent;
use AppBundle\Event\PaymentEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\InvitationEvent;
use AppBundle\Event\UserPaymentEvent;
use AppBundle\Service\IntercomService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

class IntercomListener
{
    /** @var IntercomService */
    protected $intercom;

    /**
     * @param IntercomService $intercom
     */
    public function __construct(IntercomService $intercom)
    {
        $this->intercom = $intercom;
    }

    public function onLeadUpdatedEvent(LeadEvent $event)
    {
        if ($event->getLead()->getEmail()) {
            $this->intercom->queueLead($event->getLead(), IntercomService::QUEUE_LEAD);
        }
    }

    public function onUserCreatedEvent(UserEvent $event)
    {
        $this->intercom->queue($event->getUser());
    }

    public function onUserUpdatedEvent(UserEvent $event)
    {
        $this->intercom->queue($event->getUser());
    }

    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        // move event to onPolicyStartEvent
        return;
        /*
        $this->intercom->queue($event->getPolicy()->getUser());
        $this->intercom->queuePolicy($event->getPolicy(), IntercomService::QUEUE_EVENT_POLICY_CREATED);
        */
    }

    public function onPolicyPotEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        // TODO: Trigger intercom event
    }

    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        $this->intercom->queuePolicy($event->getPolicy(), IntercomService::QUEUE_EVENT_POLICY_CANCELLED);
    }

    public function onPolicyPendingRenewedEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        $this->intercom->queuePolicy($event->getPolicy(), IntercomService::QUEUE_EVENT_POLICY_PENDING_RENEWAL);
    }

    public function onPolicyRenewedEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        $this->intercom->queuePolicy($event->getPolicy(), IntercomService::QUEUE_EVENT_POLICY_RENEWED);
    }

    public function onPolicyStartEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
        $this->intercom->queuePolicy($event->getPolicy(), IntercomService::QUEUE_EVENT_POLICY_START);

        // Eventually we want to migrate users to the policy started event
        // However, this will impact on users in the connection campaign, and so
        // send both created & started events for now until we can migrate over
        $this->intercom->queuePolicy($event->getPolicy(), IntercomService::QUEUE_EVENT_POLICY_CREATED);
    }

    public function onPolicyUnpaidEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
    }

    public function onPolicyReactivatedEvent(PolicyEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
    }

    public function onInvitationAcceptedEvent(InvitationEvent $event)
    {
        // Invitation accepted is a connection, so update both inviter & invitee
        $this->intercom->queue($event->getInvitation()->getInviter());
        $this->intercom->queue($event->getInvitation()->getInvitee());
    }

    public function onConnectionConnectedEvent(ConnectionEvent $event)
    {
        $this->intercom->queueConnection($event->getConnection());
    }

    public function onPaymentSuccessEvent(PaymentEvent $event)
    {
        // user record needs to be updated to ensure that the paid state is set correctly
        $this->intercom->queue($event->getPayment()->getPolicy()->getUser());
        $this->intercom->queuePayment($event->getPayment(), IntercomService::QUEUE_EVENT_PAYMENT_SUCCESS);
    }

    public function onPaymentFailedEvent(PaymentEvent $event)
    {
        // user record needs to be updated to ensure that the paid state is set correctly
        $this->intercom->queue($event->getPayment()->getPolicy()->getUser());
        $this->intercom->queuePayment($event->getPayment(), IntercomService::QUEUE_EVENT_PAYMENT_FAILED);
    }

    public function onPaymentFirstProblemEvent(PaymentEvent $event)
    {
        // We have a few new properties on the user that are required for the payment first problem
        // Resynce user for now to ensure everything is present.
        // Eventually can be removed if all users are re-synced or if enough time has elapsed (1 year?)
        $this->intercom->queue($event->getPayment()->getPolicy()->getUser());
        $this->intercom->queuePayment($event->getPayment(), IntercomService::QUEUE_EVENT_PAYMENT_FIRST_PROBLEM);
    }

    public function onUserPaymentFailedEvent(UserPaymentEvent $event)
    {
        $this->intercom->queueUser(
            $event->getUser(),
            IntercomService::QUEUE_EVENT_USER_PAYMENT_FAILED,
            ['reason' => $event->getReason()]
        );
    }

    public function onClaimCreatedEvent(ClaimEvent $event)
    {
        $this->intercom->queueClaim($event->getClaim(), IntercomService::QUEUE_EVENT_CLAIM_CREATED);
    }

    public function onClaimApprovedEvent(ClaimEvent $event)
    {
        $this->intercom->queueClaim($event->getClaim(), IntercomService::QUEUE_EVENT_CLAIM_APPROVED);
    }

    public function onClaimSettledEvent(ClaimEvent $event)
    {
        $this->intercom->queueClaim($event->getClaim(), IntercomService::QUEUE_EVENT_CLAIM_SETTLED);
    }

    public function onPicSureReceivedEvent(PicsureEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
    }

    public function onPicSureUndamagedEvent(PicsureEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
    }

    public function onPicSureInvalidEvent(PicsureEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
    }

    public function onPicSureDamagedEvent(PicsureEvent $event)
    {
        $this->intercom->queue($event->getPolicy()->getUser());
    }
}
