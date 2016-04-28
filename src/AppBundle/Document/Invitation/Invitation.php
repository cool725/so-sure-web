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
    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';

    /**
     * @MongoDB\Id
     */
    protected $id;

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\Date() */
    protected $cancelled;

    /** @MongoDB\Date() */
    protected $accepted;

    /** @MongoDB\Date() */
    protected $rejected;

    /** @MongoDB\Date(name="last_reinvited") */
    protected $lastReinvited;

    /** @MongoDB\Date(name="next_reinvited") */
    protected $nextReinvited;

    /** @MongoDB\Field(type="integer", name="reinvited_count") */
    protected $reinvitedCount;

    /** @MongoDB\Field(type="string") */
    protected $status;

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
    abstract public function getChannel();
    abstract public function getMaxReinvitations();
    abstract public function getInvitationDetail();

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->reinvitedCount = 0;
    }

    public function getId()
    {
        return $this->id;
    }
    
    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function getAccepted()
    {
        return $this->accepted;
    }

    public function isAccepted()
    {
        return $this->accepted !== null;
    }

    public function setAccepted($accepted)
    {
        $this->accepted = $accepted;
    }

    public function getRejected()
    {
        return $this->rejected;
    }

    public function isRejected()
    {
        return $this->rejected !== null;
    }

    public function setRejected($rejected)
    {
        $this->rejected = $rejected;
    }

    public function getCancelled()
    {
        return $this->cancelled;
    }

    public function isCancelled()
    {
        return $this->cancelled !== null;
    }

    public function setCancelled($cancelled)
    {
        $this->cancelled = $cancelled;
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

    public function getReinvitedCount()
    {
        return $this->reinvitedCount;
    }

    public function getLastReinvited()
    {
        return $this->lastReinvited;
    }

    public function setLastReinvited($lastReinvited)
    {
        $this->lastReinvited = $lastReinvited;
    }

    public function getNextReinvited()
    {
        return $this->nextReinvited;
    }

    public function setNextReinvited($nextReinvited)
    {
        $this->nextReinvited = $nextReinvited;
    }

    public function canReinvite(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        return $this->getReinvitedCount() <= $this->getMaxReinvitations() &&
            $this->getNextReinvited() < $date;
    }

    public function invite(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $this->setNextReinvited($date->add(new \DateInterval('P1D')));
    }

    public function reinvite(\DateTime $date = null)
    {
        if (!$this->canReinvite()) {
            throw new \Exception('Max invitations have been reached');
        }

        if (!$date) {
            $date = new \DateTime();
        }
        $this->setLastReinvited($date);
        if ($this->getReinvitedCount() < $this->getMaxReinvitations()) {
            $this->setNextReinvited($date->add(new \DateInterval('P1D')));
        } else {
            $this->setNextReinvited(null);
        }

        $this->reinvitedCount++;
    }

    public function hasAccepted()
    {
        return $this->getAccepted() !== null;
    }

    public function isProcessed()
    {
        return $this->isAccepted() || $this->isRejected() || $this->isCancelled();
    }

    public function getInviteeName()
    {
        if ($this->getInvitee()) {
            return $this->getInvitee()->getName();
        } elseif ($this->getName()) {
            return $this->getName();
        } else {
            return $this->getInvitationDetail();
        }
    }

    public function toApiArray($debug = false)
    {
        $data = [
            'id' => $this->getId(),
            'name' => $this->getName() ? $this->getName() : null,
            'inviter_name' => $this->getInviter() ? $this->getInviter()->getName() : null,
            'channel' => $this->getChannel(),
            'link' => $this->getLink(),
            'status' => $this->getStatus(),
            'created_date' => $this->getCreated() ? $this->getCreated()->format(\DateTime::ISO8601) : null,
            'next_reinvite_date' =>  $this->getNextReinvited() ?
                $this->getNextReinvited()->format(\DateTime::ISO8601) :
                null,
        ];

        if ($debug) {
            $data = array_merge($data, [
                'inviter_id' => $this->getInviter() ? $this->getInviter()->getId() : null,
                'invitee_id' => $this->getInvitee() ? $this->getInvitee()->getId() : null,
                'invitee_name' => $this->getInvitee() ? $this->getInvitee()->getName() : null,
            ]);
        }

        return $data;
    }
}
