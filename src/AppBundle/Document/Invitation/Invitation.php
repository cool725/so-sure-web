<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Document\User;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("invitation_type")
 * @MongoDB\DiscriminatorMap({"email"="EmailInvitation", "sms"="SmsInvitation"})
 */
abstract class Invitation
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\Date() */
    protected $accepted;

    /** @MongoDB\Date() */
    protected $rejected;

    /** @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User", inversedBy="sentInvitations") */
    protected $inviter;

    /** @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User", inversedBy="receivedInvitations") */
    protected $invitee;

    /** @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="invitations") */
    protected $policy;

    /** @MongoDB\String(name="link", nullable=true) */
    protected $link;

    /** @MongoDB\String(name="name", nullable=true) */
    protected $name;

    abstract public function isSingleUse();

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

    public function getAccepted()
    {
        return $this->accepted;
    }

    public function setAccepted($accepted)
    {
        $this->accepted = $accepted;
    }

    public function getRejected()
    {
        return $this->rejected;
    }

    public function setRejected($rejected)
    {
        $this->rejected = $rejected;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getLink()
    {
        return $this->link;
    }

    public function setLink($link)
    {
        $this->link = $link;
    }

    public function getInviter()
    {
        return $this->inviter;
    }

    public function setInviter(User $inviter)
    {
        $this->inviter = $inviter;
    }

    public function getInvitee()
    {
        return $this->invitee;
    }

    public function setInvitee(User $invitee)
    {
        $this->invitee = $invitee;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
        $policy->getUser()->addSentInvitation($this);
        //$this->setInviter($policy->getUser());
    }

    public function hasAccepted()
    {
        return $this->getAccepted() !== null;
    }
    
    public function toApiArray()
    {
        return [
            'id' => $this->getId(),
            'link' => $this->getLink(),
        ];
    }
}
