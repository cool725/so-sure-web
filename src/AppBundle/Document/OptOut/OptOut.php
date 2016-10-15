<?php

namespace AppBundle\Document\OptOut;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

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
    const OPTOUT_CAT_WEEKLY = 'weekly';
    const OPTOUT_CAT_AQUIRE = 'aquire';
    const OPTOUT_CAT_RETAIN = 'retain';

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     */
    protected $created;

    /**
     * @Assert\Choice({"all", "invitations", "weekly", "aquire", "retain"})
     * @MongoDB\Field(type="string")
     */
    protected $category;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(max="500")
     * @MongoDB\Field(type="string")
     */
    protected $notes;

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

    public function getNotes()
    {
        return $this->notes;
    }

    public function setNotes($notes)
    {
        $this->notes = $notes;
    }
}
