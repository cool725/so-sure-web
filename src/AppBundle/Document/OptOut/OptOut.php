<?php

namespace AppBundle\Document\OptOut;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("optout_type")
 * @MongoDB\DiscriminatorMap({"email"="EmailOptOut", "sms"="SmsOptOut"})
 */
abstract class OptOut
{
    const OPTOUT_CAT_ALL = 'all';
    const OPTOUT_CAT_INVITATIONS = 'invitations';

    /**
     * @MongoDB\Id
     */
    protected $id;

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\String(name="category", nullable=true) */
    protected $category;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }
    
    public function getCreated()
    {
        return $this->created;
    }

    public function getCategory()
    {
        return $this->category;
    }

    public function setCategory($category)
    {
        $this->category = $category;
    }
}
