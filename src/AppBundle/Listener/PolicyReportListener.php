<?php

namespace AppBundle\Listener;

use AppBundle\Document\Charge;
use AppBundle\Service\SmsService;
use AppBundle\Service\BranchService;
use Psr\Log\LoggerInterface;
use AppBundle\Event\PolicyEvent;

/**
 * Listens for changes that affect the policy reports and recaches the relevant lines.
 */
class PolicyReportListener
{
    /** @var PolicyService $policyService */
    protected $policyService;

    /** @var LoggerInterface $logger */
    protected $logger;

    /**
     * Creates the listener and provides for it's needs.
     * @param PolicyService   $policyService is used to do stuff to policies.
     * @param LoggerInterface $logger        logs.
     */
    public function __construct(
        PolicyService $policyService,
        LoggerInterface $logger
    ) {
        $this->policyService = $policyService;
        $this->logger = $logger;
    }

    public function onUserNameUpdated(UserEvent $event)
    {
        foreach ($user->getPolicies() as $policy) {
            $this->policyService->generateReportLines($policy);
        }
    }

    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyStartEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyExpiredEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyRenewedEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyCashbackEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyUnpaidEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyReactivatedEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyUpdatedPremiumEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyUpdatedBillingEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyUpgradedEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyUpdatedPotEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }

    public function onPolicyPaymentMethodChangedEvent(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }
}
