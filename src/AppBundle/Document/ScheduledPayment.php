<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ScheduledPaymentRepository")
 * @Gedmo\Loggable
 */
class ScheduledPayment
{
    use CurrencyTrait;

    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    const TYPE_SCHEDULED = 'scheduled';
    const TYPE_RESCHEDULED = 'rescheduled';

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * @Assert\Choice({"scheduled", "success", "failed", "cancelled"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @Assert\Choice({"scheduled", "rescheduled"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
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
     */
    protected $policy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Payment", inversedBy="scheduledPayment", cascade={"persist"})
     * @Gedmo\Versioned
     */
    protected $payment;

    public function __construct()
    {
        $this->created = new \DateTime();
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

    public function getScheduled()
    {
        return $this->scheduled;
    }

    public function getScheduledDay()
    {
        return $this->getScheduled()->format('j');
    }

    public function setPayment(Payment $payment)
    {
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

    public function cancel()
    {
        $this->setStatus(self::STATUS_CANCELLED);
    }

    public function reschedule($date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        } else {
            $date = clone $date;
        }
        $date->add(new \DateInterval('P7D'));

        $rescheduled = new ScheduledPayment();
        $rescheduled->setType(self::TYPE_RESCHEDULED);
        $rescheduled->setPolicy($this->getPolicy());
        $rescheduled->setAmount($this->getAmount());
        $rescheduled->setStatus(self::STATUS_SCHEDULED);
        $rescheduled->setScheduled($date);

        return $rescheduled;
    }

    public function isBillable($prefix = null)
    {
        return $this->getStatus() == self::STATUS_SCHEDULED &&
                $this->getPolicy()->isValidPolicy($prefix) &&
                $this->getPolicy()->isBillablePolicy();
    }

    public function canBeRun(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        return $this->getScheduled() <= $date;
    }

    public function hasCorrectBillingDay()
    {
        if ($this->getType() == self::TYPE_RESCHEDULED) {
            return null;
        }

        return $this->getScheduledDay() == $this->policy->getBillingDay();
    }

    public function toApiArray()
    {
        return [
            'date' => $this->getScheduled() ? $this->getScheduled()->format(\DateTime::ATOM) : null,
            'amount' => $this->getAmount() ? $this->toTwoDp($this->getAmount()) : null,
        ];
    }

    public static function sumScheduledPaymentAmounts($scheduledPayments, $prefix = null)
    {
        $total = 0;
        foreach ($scheduledPayments as $scheduledPayment) {
            if ($scheduledPayment->isBillable($prefix)) {
                $total += $scheduledPayment->getAmount();
            }
        }

        return $total;
    }
}
