<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\ScheduledPayment;

/**
 * Represents something occurring with a scheduled payment.
 */
class ScheduledPaymentEvent extends Event
{
    const EVENT_FAILED = 'event.scheduledPayment.failed';

    /** @var ScheduledPayment */
    protected $scheduledPayment;

    /** @var \DateTime */
    protected $date;

    /**
     * Constructs the event and puts in the scheduled payment that it relates to and sets it's creation date.
     * @param ScheduledPayment $scheduledPayment is the relevant scheduled payment.
     */
    public function __construct(ScheduledPayment $scheduledPayment, \DateTime $date = null)
    {
        $this->scheduledPayment = $scheduledPayment;
        if (!$date) {
            $date = new \DateTime();
        }
        $this->date = $date;
    }

    /**
     * Gives you the scheduled payment the event is about.
     * @return ScheduledPayment the scheduled payment.
     */
    public function getScheduledPayment()
    {
        return $this->scheduledPayment;
    }

    /**
     * Gives you the date at which this event was created.
     * @return \DateTime the date.
     */
    public function getDate()
    {
        return $this->date;
    }
}
