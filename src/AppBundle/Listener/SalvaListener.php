<?php

namespace AppBundle\Listener;

use AppBundle\Classes\Salva;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Service\SalvaExportService;
use Doctrine\ODM\MongoDB\DocumentManager;

class SalvaListener
{
    /** @var SalvaExportService */
    protected $salvaService;

    /**
     * SalvaListener constructor.
     * @param SalvaExportService $salvaService
     */
    public function __construct(SalvaExportService $salvaService)
    {
        $this->salvaService = $salvaService;
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyCreatedEvent(PolicyEvent $event)
    {
        $this->queue($event, SalvaExportService::QUEUE_CREATED);
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicySalvaIncrementEvent(PolicyEvent $event)
    {
        $this->queue($event, SalvaExportService::QUEUE_UPDATED);
    }

    /**
     * @param PolicyEvent $event
     */
    public function onPolicyCancelledEvent(PolicyEvent $event)
    {
        $this->queue($event, SalvaExportService::QUEUE_CANCELLED);
    }

    private function queue(PolicyEvent $event, $action)
    {
        $policy = $event->getPolicy();
        $this->salvaService->queue($policy, $action);
    }
}
