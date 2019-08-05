<?php

namespace AppBundle\Document\Form;

use DateTime;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ScheduledPayment;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represents a request to create a scheduled payment via a form.
 */
class CancelScheduledPayment
{
    /**
     * @var string
     * @Assert\NotNull(message="Must justify manual payment")
     */
    protected $notes;

    /**
     * @return mixed
     */
    public function getNotes()
    {
        return $this->notes;
    }

    /**
     * @param mixed $notes
     * @return CancelScheduledPayment
     */
    public function setNotes($notes)
    {
        $this->notes = $notes;
        return $this;
    }
}
