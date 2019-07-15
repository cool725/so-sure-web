<?php

namespace AppBundle\Document\Form;

use DateTime;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ScheduledPayment;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represents a request to create a scheduled payment via a form.
 */
class CreateScheduledPayment
{
    /**
     * @var \DateTime
     * @Assert\NotNull(message="Date must be set")
     */
    protected $date;

    /**
     * @var string
     * @Assert\NotNull(message="Must justify manual payment")
     */
    protected $notes;

    /**
     * @var array
     */
    protected $disabledDates;

    public function getDate()
    {
        return $this->date;
    }

    public function setDate($date)
    {
        $this->date = $date;
    }

    /**
     * @return mixed
     */
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * @param mixed $notes
     * @return CreateScheduledPayment
     */
    public function setNotes($notes)
    {
        $this->notes = $notes;
        return $this;
    }

    public function getDisabledDatesJson()
    {
        return json_encode($this->disabledDates);
    }

    /**
     * @return array
     */
    public function getDisabledDates(): array
    {
        return $this->disabledDates;
    }

    /**
     * @param array $disabledDates
     * @return CreateScheduledPayment
     */
    public function setDisabledDates(array $disabledDates): CreateScheduledPayment
    {
        $this->disabledDates = $disabledDates;
        return $this;
    }

    public function __construct($bankHolidays, $scheduledPayments)
    {
        $this->createDisabledDateArray($bankHolidays, $scheduledPayments);
    }


    protected function createDisabledDateArray($bankHolidays, $scheduledPayments)
    {
        $now = new \DateTime();
        foreach ($bankHolidays as $bankHoliday) {
            if ($bankHoliday > $now) {
                $this->disabledDates[] = $bankHoliday;
            }
        }
        /** @var ScheduledPayment $scheduledPayment */
        foreach ($scheduledPayments as $scheduledPayment) {
            if ($scheduledPayment->getScheduled() > $now) {
                $this->disabledDates[] = $scheduledPayment->getScheduled();
            }
        }

    }
}
