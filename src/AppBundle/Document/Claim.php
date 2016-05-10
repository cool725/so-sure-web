<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/** @MongoDB\Document */
class Claim
{
    const TYPE_LOSS = 'loss';
    const TYPE_THEFT = 'theft';
    const TYPE_DAMAGE = 'damage';

    const STATUS_OPEN = 'open';
    const STATUS_SETTLED = 'settled';
    const STATUS_DECLINED = 'declined';
    const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     */
    protected $policy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     */
    public $handler;

    /** @MongoDB\Date() */
    protected $date;

    /** @MongoDB\Field(type="string") */
    protected $number;

    /** @MongoDB\Field(type="string") */
    protected $type;

    /** @MongoDB\Field(type="string") */
    protected $status;

    /** @MongoDB\Field(type="boolean", name="suspected_fraud") */
    protected $suspectedFraud;

    public function __construct()
    {
        $this->date = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate($date)
    {
        $this->date = $date;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    public function getHandler()
    {
        return $this->handler;
    }

    public function setHandler($handler)
    {
        $this->handler = $handler;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type)
    {
        $this->type = $type;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getNumber()
    {
        return $this->number;
    }

    public function setNumber($number)
    {
        $this->number = $number;
    }

    public function getSuspectedFraud()
    {
        return $this->suspectedFraud;
    }

    public function setSuspectedFraud($suspectedFraud)
    {
        $this->suspectedFraud = $suspectedFraud;
    }

    public function isMonetaryClaim()
    {
        return in_array($this->getStatus(), [self::STATUS_SETTLED]);
    }
}
