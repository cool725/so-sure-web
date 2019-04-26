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

    /** @var boolean */
    protected $canRetry;

    /**
     * Constructs the event and puts in the scheduled payment that it relates to and sets it's creation date.
     * @param ScheduledPayment $scheduledPayment is the relevant scheduled payment.
     */
    public function __construct(ScheduledPayment $scheduledPayment, \DateTime $date = null, $canRetry = true)
    {
        $this->scheduledPayment = $scheduledPayment;
        if (!$date) {
            $date = new \DateTime();
        }
        $this->date = $date;
        $this->canRetry = $canRetry;
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

    /**
     * If this is false then there is no point retrying the scheduled payment. True value may not necessarily mean that
     * a retry will succeed or should be attempted.
     * @return boolean as described.
     */
    public function getCanRetry()
    {
        return $this->canRetry;
    }
}
