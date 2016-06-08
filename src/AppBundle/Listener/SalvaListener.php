<?php

namespace AppBundle\Listener;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\SalvaPolicyEvent;
use AppBundle\Service\SalvaExportService;
use Doctrine\ODM\MongoDB\DocumentManager;

class SalvaListener
{
    protected $salvaService;

    /**
     * @param $redis
     */
    public function __construct($salvaService)
    {
        $this->salvaService = $salvaService;
    }

    /**
     * @param SalvaPolicyEvent $event
     */
    public function onSalvaPolicyCreatedEvent(SalvaPolicyEvent $event)
    {
        $this->queue($event, SalvaExportService::QUEUE_CREATED);
    }

    /**
     * @param SalvaPolicyEvent $event
     */
    public function onSalvaPolicyUpdatedEvent(SalvaPolicyEvent $event)
    {
        $this->queue($event, SalvaExportService::QUEUE_UPDATED);
    }

    /**
     * @param SalvaPolicyEvent $event
     */
    public function onSalvaPolicyCancelledEvent(SalvaPolicyEvent $event)
    {
        $this->queue($event, SalvaExportService::QUEUE_CANCELLED);
    }

    private function queue(SalvaPolicyEvent $event, $action)
    {
        $policy = $event->getPhonePolicy();
        $this->salvaService->queue($policy, $action);
    }
}
