<?php

namespace AppBundle\Document;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Exception\ScheduledPaymentException;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\BacsPayment;
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
    const STATUS_REVERTED = 'reverted';

    const TYPE_SCHEDULED = 'scheduled';
    const TYPE_RESCHEDULED = 'rescheduled';
    // Refund cancellations should always occur regardless of policy status
    const TYPE_REFUND = 'refund';
    const TYPE_ADMIN = 'admin';
    const TYPE_USER_WEB = 'user-web';
    const TYPE_USER_MOBILE = 'user-mobile';

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
     * @Assert\Choice({"scheduled", "success", "failed", "cancelled", "pending", "reverted"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @Assert\Choice({"scheduled", "rescheduled", "admin", "user-web", "user-mobile", "refund"}, strict=true)
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
     * @Assert\Range(min=-200,max=200)
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

    /**
     * @MongoDB\EmbedOne(targetDocument="IdentityLog")
     * @Gedmo\Versioned
     */
    protected $identityLog;

    /**
     * If this scheduled payment is a rescheduling of an older scheduled payment, then this field will contain the
     * old one.
     * @MongoDB\ReferenceOne(targetDocument="ScheduledPayment")
     * @Gedmo\Versioned
     * @var ScheduledPayment|null
     */
    protected $previousAttempt;

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

    public function getPaymentSource()
    {
        if ($this->getType() == self::TYPE_USER_WEB) {
            return Payment::SOURCE_WEB;
        } elseif ($this->getType() == self::TYPE_USER_MOBILE) {
            return Payment::SOURCE_MOBILE;
        } elseif ($this->getType() == self::TYPE_ADMIN) {
            return Payment::SOURCE_ADMIN;
        } elseif ($this->getType() == self::TYPE_REFUND) {
            return Payment::SOURCE_SYSTEM;
        } else {
            return Payment::SOURCE_TOKEN;
        }
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
            $this->scheduled->setTimezone(SoSure::getSoSureTimezone());
        }

        return $this->scheduled;
    }

    public function getScheduledDay()
    {
        return $this->getScheduled() ? $this->getScheduled()->format('j') : null;
    }

    public function setPayment(Payment $payment = null)
    {
        if (!$payment && $this->payment) {
            $this->payment->setScheduledPayment(null);
        }

        if ($payment) {
            $payment->setScheduledPayment($this);
        }

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

    public function getIdentityLog()
    {
        return $this->identityLog;
    }

    public function setIdentityLog($identityLog)
    {
        $this->identityLog = $identityLog;
    }

    public function setPreviousAttempt(ScheduledPayment $previousAttempt = null)
    {
        $this->previousAttempt = $previousAttempt;
    }

    public function getPreviousAttempt()
    {
        return $this->previousAttempt;
    }

    /**
     * Sets the scheduled payment as cancelled.
     * @param string $note is a message to append to the scheduled payment's notes about cancellation. Not optional.
     */
    public function cancel($note)
    {
        $this->setNotes($this->getNotes().". ".$note);
        $this->setStatus(self::STATUS_CANCELLED);
    }

    public function reschedule($date = null, $days = null)
    {
        if (!$this->getPolicy()) {
            throw new \Exception(sprintf('Missing policy for scheduled payment %s', $this->getId()));
        }

        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        } else {
            $date = clone $date;
        }

        if ($days === null) {
            if ($this->getPolicy()->getPolicyOrUserBacsPaymentMethod()) {
                $days = 6;
            } else {
                $days = 7;
            }
        }
        $date->add(new \DateInterval(sprintf('P%dD', $days)));

        $rescheduled = new ScheduledPayment();
        $rescheduled->setType(self::TYPE_RESCHEDULED);
        $rescheduled->setPolicy($this->getPolicy());
        $rescheduled->setAmount($this->getAmount());
        $rescheduled->setStatus(self::STATUS_SCHEDULED);
        $rescheduled->setScheduled($date);
        $rescheduled->setPreviousAttempt($this);
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

    public function isBillable($includePending = false)
    {
        $status = $this->getStatus();
        $pending = false;
        if ($includePending) {
            $pending = $status == self::STATUS_PENDING;
        }
        // Admin should ignore billable status to allow an expired policy to be billed
        if ($this->getType() == self::TYPE_ADMIN) {
            $scheduled = $status == self::STATUS_SCHEDULED;
            return ($scheduled || $pending) &&
                    $this->getPolicy()->isPolicy();
        } else {
            $scheduled = $status == self::STATUS_SCHEDULED;
            return ($scheduled || $pending) &&
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

    public function validateRunable(\DateTime $date = null)
    {
        if (!$this->getPolicy()->isValidPolicy()) {
            throw new ScheduledPaymentException(sprintf(
                'Scheduled payment %s policy is not valid.',
                $this->getId()
            ));
        }

        if (!$this->isBillable()) {
            throw new ScheduledPaymentException(sprintf(
                'Scheduled payment %s is not billable (status: %s)',
                $this->getId(),
                $this->getStatus()
            ));
        }

        if (!$this->canBeRun($date)) {
            throw new ScheduledPaymentException(sprintf(
                'Scheduled payment %s can not yet be run (scheduled: %s)',
                $this->getId(),
                $this->getScheduled() ? $this->getScheduled()->format('Y-m-d H:i:s') : '?'
            ));
        }

        if ($this->getPayment() && $this->getPayment()->isSuccess()) {
            throw new ScheduledPaymentException(sprintf(
                'Payment already received for scheduled payment %s',
                $this->getId()
            ));
        }
    }

    public function hasCorrectBillingDay()
    {
        /**
         * If the billing day has not been set, then the scheduled payments will be against the
         * policy start date. So we don't need to do anything if billing day is null.
         */
        if (!$this->policy->getBillingDay()) {
            return true;
        }

        if ($this->getType() == self::TYPE_RESCHEDULED || !$this->getScheduled()) {
            return null;
        }

        if ($this->getScheduledDay() == $this->policy->getBillingDay()) {
            return true;
        }


        $diff = $this->getScheduled()->diff($this->policy->getBilling());
        $adjustedBilling = $this->setDayOfMonth(
            $this->getScheduled(),
            $this->policy->getBillingDay()
        );

        // Hack for a off by one hour timezone issue between billing & scheduled
        // TODO: Fix scheduled times
        $diff = $this->getScheduled()->diff($adjustedBilling);
        if ($diff->d == 0 && $diff->h <= 1) {
            return true;
        }

        /**
         * BACs cannot be scheduled on the weekend or a bank holiday, so we would expect
         * there to be a difference between the scheduled day and the billing day.
         * At two points in the year, this difference can be 4 days, so as long as the
         * difference is 4 or less, but still in the same month we are good to go.
         * Customers can also change payment type to card and keep their schedule,
         * so we cannot rely on this only happening for BACs users.
         */
        if ($diff->d <= 4 && $diff->m === 0) {
            return true;
        }

        return false;
    }

    /**
     * Tells you if a scheduled payment is a rescheduled one and if it is close enough to it's original payment that it
     * is ok to let it go ahead. If this is not a rescheduled payment then it will always fail.
     * @return boolean|null true if we can go ahead and false if not, and null if the scheduled payment is not even bacs
     *                      or rescheduled.
     */
    public function rescheduledInTime()
    {
        $paymentType = $this->getPolicy()->getPolicyOrUserPaymentMethod()->getType();
        if ($paymentType !== PaymentMethod::TYPE_BACS || $this->type !== self::TYPE_RESCHEDULED) {
            return null;
        }
        $origin = $this;
        $limiter = 0;
        while ($origin->getPreviousAttempt() && $limiter < 100) {
            $origin = $origin->getPreviousAttempt();
            $limiter++;
        }
        if ($origin == $this || $limiter == 100) {
            return false;
        }
        $date = $origin->getScheduled();
        return $date < $this->getScheduled() &&
            $date >= $this->subDays($this->getScheduled(), BacsPayment::DAYS_REPRESENTING);
    }

    public function toApiArray()
    {
        return [
            'date' => $this->getScheduled() ? $this->getScheduled()->format(\DateTime::ATOM) : null,
            'amount' => $this->getAmount() ? $this->toTwoDp($this->getAmount()) : null,
            'type' => $this->getPolicy() && $this->getPolicy()->getPaymentMethod() ?
                $this->getPolicy()->getPaymentMethod()->getType() :
                null
        ];
    }

    public static function sumScheduledPaymentAmounts($scheduledPayments, $includePending = false)
    {
        $total = 0;
        /** @var ScheduledPayment $scheduledPayment */
        foreach ($scheduledPayments as $scheduledPayment) {
            if ($scheduledPayment->isBillable($includePending)) {
                $total += $scheduledPayment->getAmount();
            }
        }

        return $total;
    }
}
