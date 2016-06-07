<?php

namespace AppBundle\Listener;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\SalvaPolicyEvent;
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
     * @param UserEvent $event
     */
    public function onSalvaPolicyUpdatedEvent(SalvaPolicyEvent $event)
    {
        $policy = $event->getPhonePolicy();
        $this->salvaService->queue($policy);
    }
}
