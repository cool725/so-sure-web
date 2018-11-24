<?php

namespace AppBundle\Document\Note;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\CurrencyTrait;

/**
 * @MongoDB\EmbeddedDocument()
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({
 *      "standard"="StandardNote",
 *      "call"="CallNote"
 * })
 */
abstract class Note
{
    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @MongoDB\Index(sparse=true)
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="500")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $notes;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     * @Gedmo\Versioned
     */
    protected $user;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="500")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $userName;

    public function __construct()
    {
        $this->date = \DateTime::createFromFormat('U', time());
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate(\DateTime $date)
    {
        $this->date = $date;
    }

    public function setNotes($notes)
    {
        $this->notes = $notes;
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
        $this->userName = $user->getName();
    }

    public function getUserName()
    {
        return $this->userName;
    }

    public function getType()
    {
        if ($this instanceof StandardNote) {
            return 'standard';
        } elseif ($this instanceof CallNote) {
            return 'call';
        }

        return 'unknown';
    }
}
