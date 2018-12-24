<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\Payment\Payment;
use AppBundle\Classes\SoSure;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ScheduledPaymentRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class ScheduledPayment
{
    use DateTrait;
    use CurrencyTrait;

    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_PENDING = 'pending';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    const TYPE_SCHEDULED = 'scheduled';
    const TYPE_RESCHEDULED = 'rescheduled';
    const TYPE_ADMIN = 'admin';

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * @Assert\Choice({"scheduled", "success", "failed", "cancelled", "pending"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @Assert\Choice({"scheduled", "rescheduled", "admin"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @MongoDB\Index(unique=false, sparse=true)
     */
    protected $scheduled;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $amount;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy", inversedBy="scheduledPayments")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $policy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Payment\Payment",
     *     inversedBy="scheduledPayment", cascade={"persist"})
     * @Gedmo\Versioned
     */
    protected $payment;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="200")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $notes;

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
        $this->type = self::TYPE_SCHEDULED;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;
    }
    
    public function getAmount()
    {
        return $this->amount;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setType($type)
    {
        $this->type = $type;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setScheduled($scheduled)
    {
        $this->scheduled = $scheduled;
    }

    /**
     * @return \DateTime|null
     */
    public function getScheduled()
    {
        if ($this->scheduled) {
            $this->scheduled->setTimezone(new \DateTimeZone(SoSure::TIMEZONE));
        }

        return $this->scheduled;
    }

    public function getScheduledDay()
    {
        return $this->getScheduled() ? $this->getScheduled()->format('j') : null;
    }

    public function setPayment(Payment $payment)
    {
        $payment->setScheduledPayment($this);
        $this->payment = $payment;
    }

    public function getPayment()
    {
        return $this->payment;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setNotes($notes)
    {
        $this->notes = $notes;
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function cancel()
    {
        $this->setStatus(self::STATUS_CANCELLED);
    }

    public function reschedule($date = null, $days = 7)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        } else {
            $date = clone $date;
        }
        $date->add(new \DateInterval(sprintf('P%dD', $days)));

        $rescheduled = new ScheduledPayment();
        $rescheduled->setType(self::TYPE_RESCHEDULED);
        $rescheduled->setPolicy($this->getPolicy());
        $rescheduled->setAmount($this->getAmount());
        $rescheduled->setStatus(self::STATUS_SCHEDULED);
        $rescheduled->setScheduled($date);

        return $rescheduled;
    }

    public function adminReschedule(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        } else {
            $date = clone $date;
        }

        if ($date->format('H') >= 14) {
            $date = $this->addBusinessDays($date, 1);
        }

        $this->setScheduled($date);
        $this->setType(self::TYPE_ADMIN);
    }

    public function isBillable()
    {
        // Admin should ignore billable status to allow an expired policy to be billed
        if ($this->getType() == self::TYPE_ADMIN) {
            return $this->getStatus() == self::STATUS_SCHEDULED &&
                    $this->getPolicy()->isPolicy();
        } else {
            return $this->getStatus() == self::STATUS_SCHEDULED &&
                    $this->getPolicy()->isPolicy() &&
                    $this->getPolicy()->isBillablePolicy();
        }
    }

    public function canBeRun(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        return $this->getScheduled() <= $date;
    }

    public function validateRunable($prefix = null, \DateTime $date = null)
    {
        if (!$this->getPolicy()->isValidPolicy($prefix)) {
            throw new \Exception(sprintf(
                'Scheduled payment %s policy is not valid. Invalid Prefix?',
                $this->getId()
            ));
        }

        if (!$this->isBillable()) {
            throw new \Exception(sprintf(
                'Scheduled payment %s is not billable (status: %s)',
                $this->getId(),
                $this->getStatus()
            ));
        }

        if (!$this->canBeRun($date)) {
            throw new \Exception(sprintf(
                'Scheduled payment %s can not yet be run (scheduled: %s)',
                $this->getId(),
                $this->getScheduled() ? $this->getScheduled()->format('Y-m-d H:i:s') : '?'
            ));
        }

        if ($this->getPayment() && $this->getPayment()->isSuccess()) {
            throw new \Exception(sprintf(
                'Payment already received for scheduled payment %s',
                $this->getId()
            ));
        }
    }

    public function hasCorrectBillingDay()
    {
        if ($this->getType() == self::TYPE_RESCHEDULED || !$this->getScheduled()) {
            return null;
        }

        if ($this->getScheduledDay() == $this->policy->getBillingDay()) {
            return true;
        }

        $diff = $this->getScheduled()->diff($this->policy->getBilling());
        $adjustedBilling = clone $this->policy->getBilling();
        $adjustedBilling = $adjustedBilling->add(new \DateInterval(sprintf('P%dM', $diff->m)));

        // Hack for a off by one hour timezone issue between billing & scheduled
        // TODO: Fix scheduled times
        $diff = $this->getScheduled()->diff($adjustedBilling);
        if ($diff->d == 0 && $diff->h <= 1) {
            return true;
        }

        return false;
    }

    public function toApiArray()
    {
        return [
            'date' => $this->getScheduled() ? $this->getScheduled()->format(\DateTime::ATOM) : null,
            'amount' => $this->getAmount() ? $this->toTwoDp($this->getAmount()) : null,
            'type' => 'judo', // TODO: scheduled payments are only judo for now, but this isn't great
        ];
    }

    public static function sumScheduledPaymentAmounts($scheduledPayments)
    {
        $total = 0;
        foreach ($scheduledPayments as $scheduledPayment) {
            if ($scheduledPayment->isBillable()) {
                $total += $scheduledPayment->getAmount();
            }
        }

        return $total;
    }
}
