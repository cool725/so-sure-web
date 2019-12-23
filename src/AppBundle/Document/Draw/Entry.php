<?php

namespace AppBundle\Document\Draw;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;
use AppBundle\Document\User;
use AppBundle\Document\Lead;

/**
 * @MongoDB\EmbeddedDocument()
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Entry
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $created;


    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     */
    protected $user;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Lead")
     */
    protected $lead;


    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function setCreated($created)
    {
        $this->created = $created;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function getLead()
    {
        return $this->lead;
    }

    public function setLead(Lead $lead)
    {
        $this->lead = $lead;
    }
}
