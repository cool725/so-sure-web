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
}
