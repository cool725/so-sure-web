<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use AppBundle\Event\PaymentEvent;
use AppBundle\Event\UserPaymentEvent;
use AppBundle\Service\MixpanelService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

class MixpanelListener
{
    /** @var MixpanelService */
    protected $mixpanel;

    /**
     * @param MixpanelService $intercom
     */
    public function __construct(MixpanelService $mixpanel)
    {
        $this->mixpanel = $mixpanel;
    }

    public function onPaymentSuccessEvent(PaymentEvent $event)
    {
        $payment = $event->getPayment();
        $this->mixpanel->queueTrackWithUser($payment->getUser(), MixpanelService::EVENT_PAYMENT, [
            'amount' => $payment->getAmount(),
        ]);
    }
}
