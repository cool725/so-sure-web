<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Payment\Payment;

class PaymentEvent extends Event
{
    const EVENT_SUCCESS = 'event.payment.success';
    const EVENT_FAILED = 'event.payment.failed';

    /** @var Payment */
    protected $payment;

    public function __construct(Payment $payment)
    {
        $this->payment = $payment;
    }

    public function getPayment()
    {
        return $this->payment;
    }
}
