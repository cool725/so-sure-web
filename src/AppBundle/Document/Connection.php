<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable
 */
class Connection
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     */
    protected $linkedUser;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy")
     * @Gedmo\Versioned
     */
    protected $sourcePolicy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy")
     * @Gedmo\Versioned
     */
    protected $linkedPolicy;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $value;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $initialValue;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User", name="replacement_user")
     * @Gedmo\Versioned
     */
    protected $replacementUser;

    public function __construct()
    {
        $this->date = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getLinkedUser()
    {
        return $this->linkedUser;
    }

    public function setLinkedUser(User $user)
    {
        $this->linkedUser = $user;
    }

    public function getSourcePolicy()
    {
        return $this->sourcePolicy;
    }

    public function setSourcePolicy(Policy $policy)
    {
        $this->sourcePolicy = $policy;
    }

    public function getLinkedPolicy()
    {
        return $this->linkedPolicy;
    }

    public function setLinkedPolicy(Policy $policy)
    {
        $this->linkedPolicy = $policy;
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
