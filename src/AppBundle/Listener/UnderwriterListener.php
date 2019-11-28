<?php

namespace AppBundle\Listener;

use AppBundle\Classes\Salva;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Service\SalvaExportService;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Handles policy events that are significant to underwriters and sends it to the appropriate one.
 */
class UnderwriterListener
{
    /** @var SalvaExportService */
    protected $salvaExportService;

    /**
     * Injects the dependencies.
     * @param SalvaExportService    $salvaExportService    is notified regarding salva policies.
     */
    public function __construct(SalvaExportService $salvaExportService)
    {
        $this->salvaExportService = $salvaExportService;
    }

    /**
     * Called when a policy is created.
     * @param PolicyEvent $event
     */
    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if ($policy instanceof SalvaPhonePolicy) {
            $this->salvaExportService->queue($policy, SalvaExportService::QUEUE_CREATED);
        } elseif ($policy instanceof HelvetiaPhonePolicy) {
            // TODO: some kinda helvetia stuff.
        }
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicySalvaIncrementEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if ($policy instanceof SalvaPhonePolicy) {
            $this->salvaExportService->queue($policy, SalvaExportService::QUEUE_UPDATED);
        } elseif ($policy instanceof HelvetiaPhonePolicy) {
            // TODO: some kinda helvetia stuff.
        }
    }

    /**
     * Called when a policy is cancelled.
     * @param PolicyEvent $event
     */
    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        if ($policy instanceof SalvaPhonePolicy) {
            $this->salvaExportService->queue($policy, SalvaExportService::QUEUE_CANCELLED);
        } elseif ($policy instanceof HelvetiaPhonePolicy) {
            // TODO: some kinda helvetia stuff.
        }
    }
}
