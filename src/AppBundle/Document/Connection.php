<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/** @MongoDB\EmbeddedDocument */
class Connection
{
    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     */
    public $user;

    /** @MongoDB\Date() */
    public $date;

    /** @MongoDB\Field(type="float") */
    public $value;

    public function __construct()
    {
        $this->date = new \DateTime();
    }

    public function toApiArray()
    {
        return [
            'name' => $this->user ? $this->user->getName() : null,
            'date' => $this->date ? $this->date->format(\DateTime::ISO8601) : null,
        ];
    }
}
