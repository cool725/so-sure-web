<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use AppBundle\Event\PaymentEvent;
use AppBundle\Event\PolicyEvent;
use AppBundle\Event\UserPaymentEvent;
use AppBundle\Service\PushService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

class PushListener
{
    /** @var PushService */
    protected $push;

    /**
     * @param PushService $push
     */
    public function __construct(PushService $push)
    {
        $this->push = $push;
    }

    public function onPolicyPendingRenewalEvent(PolicyEvent $event)
    {
        $policy = $event->getPolicy();
        $this->push->sendToUser(PushService::MESSAGE_GENERAL, $policy->getUser(), sprintf(
            'Your policy is ending soon. Renew today to keep your phone protected against Theft, Loss, Accidental Damage, and more.'
        ), null, null, $policy);
    }
}
