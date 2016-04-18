<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/** @MongoDB\EmbeddedDocument */
class Claim
{
    const TYPE_LOSS = 'loss';
    const TYPE_THEFT = 'theft';
    const TYPE_DAMAGE = 'damage';
    const TYPE_DECLINED = 'declined';
    const TYPE_WITHDRAWN = 'withdrawn';

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

    /** @MongoDB\Field(type="boolean", name="suspected_fraud") */
    protected $suspectedFraud;

    public function __construct()
    {
        $this->date = new \DateTime();
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate($date)
    {
        $this->date = $date;
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
        return in_array($this->getType(), [self::TYPE_DAMAGE, self::TYPE_LOSS, self::TYPE_THEFT]);
    }
}
