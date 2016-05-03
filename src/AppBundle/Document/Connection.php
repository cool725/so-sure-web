<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/** @MongoDB\EmbeddedDocument */
class Connection
{
    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     */
    protected $user;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy")
     */
    protected $policy;

    /** @MongoDB\Date() */
    protected $date;

    /** @MongoDB\Field(type="float") */
    protected $value;

    /** @MongoDB\Field(type="float") */
    protected $initialValue;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User", name="replacement_user")
     */
    protected $replacementUser;

    public function __construct()
    {
        $this->date = new \DateTime();
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser($user)
    {
        $this->user = $user;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate($date)
    {
        $this->date = $date;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setValue($value)
    {
        $this->value = $value;
        if (!$this->getInitialValue()) {
            $this->initialValue = $value;
        }
    }

    public function clearValue()
    {
        $this->value = 0;
    }

    public function getInitialValue()
    {
        return $this->initialValue;
    }

    public function getReplacementUser()
    {
        return $this->replacementUser;
    }

    public function setReplacementUser($replacementUser)
    {
        $this->replacementUser = $replacementUser;
    }

    public function toApiArray()
    {
        return [
            'name' => $this->getUser() ? $this->getUser()->getName() : null,
            'date' => $this->getDate() ? $this->getDate()->format(\DateTime::ISO8601) : null,
        ];
    }
}
