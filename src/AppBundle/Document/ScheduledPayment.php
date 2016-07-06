<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ScheduledPaymentRepository")
 * @Gedmo\Loggable
 */
class ScheduledPayment
{
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
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $scheduled;

    /**
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
}
