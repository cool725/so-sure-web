<?php

namespace AppBundle\Document\Invitation;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\GravatarTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\Invitation\InvitationRepository")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("invitation_type")
 * @MongoDB\DiscriminatorMap({
 *          "email"="EmailInvitation",
 *          "sms"="SmsInvitation",
 *          "scode"="SCodeInvitation",
 *          "facebook"="FacebookInvitation",
 *          "appNative"="AppNativeShareInvitation"
 * })
 * @MongoDB\Index(keys={"email"="asc", "mobile"="asc", "policy.id"="asc"}, sparse="true")
 */
abstract class Invitation
{
    use GravatarTrait;

    const STATUS_SENT = 'sent';
    const STATUS_FAILED = 'failed';
    const STATUS_SKIPPED = 'skipped';

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $created;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $cancelled;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $accepted;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $rejected;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $lastReinvited;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $nextReinvited;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="integer")
     */
    protected $reinvitedCount;

    /**
     * @Assert\Choice({"sent", "failed", "skipped"}, strict=true)
     * @MongoDB\Field(type="string")
     */
    protected $status;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User", inversedBy="sentInvitations")
     * @var User
     */
    protected $inviter;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User", inversedBy="receivedInvitations")
     * @var User|null
     */
    protected $invitee;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="invitations")
     * @var Policy
     */
    protected $policy;

    /**
     * @Assert\Url(protocols = {"http", "https"})
     * @MongoDB\Field(type="string")
     */
    protected $link;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="250")
     * @MongoDB\Field(type="string")
     */
    protected $name;

    abstract public function isSingleUse();
    abstract public function getChannel();
    abstract public function getMaxReinvitations();
    abstract public function getInvitationDetail();
    abstract public function getChannelDetails();

    /**
     * Gives the policy which is active in initiating the invitation process in a business sense.
     * Basically just means that if it's an scode invite the invitee should be returned instead of the inviter.
     * @return Policy|null the policy which shared the invite.
     */
    abstract public function getSharerPolicy();

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
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

    public function setCreated(\DateTime $created)
    {
        $this->created = $created;
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

    /**
     * @return User
     */
    public function getInviter()
    {
        return $this->inviter;
    }

    public function setInviter(User $inviter)
    {
        $this->inviter = $inviter;
    }

    /**
     * @return User|null
     */
    public function getInvitee()
    {
        return $this->invitee;
    }

    public function setInvitee(User $invitee = null)
    {
        $this->invitee = $invitee;
    }

    /**
     * @return Policy
     */
    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
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

    public function setLastReinvited(\DateTime $lastReinvited)
    {
        $this->lastReinvited = $lastReinvited;
    }

    public function getNextReinvited()
    {
        return $this->nextReinvited;
    }

    public function setNextReinvited(\DateTime $nextReinvited = null)
    {
        $this->nextReinvited = $nextReinvited;
    }

    public function canReinvite(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!$this->getPolicy() || !in_array($this->getPolicy()->getStatus(), [
            Policy::STATUS_ACTIVE,
            Policy::STATUS_UNPAID,
        ])) {
            return false;
        }

        return $this->getReinvitedCount() <= $this->getMaxReinvitations() &&
            $this->getNextReinvited() < $date;
    }

    public function invite(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $this->setNextReinvited($date->add(new \DateInterval('P1D')));
    }

    public function reinvite(\DateTime $date = null)
    {
        if (!$this->canReinvite()) {
            throw new \Exception('Max invitations have been reached');
        }

        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $this->setLastReinvited($date);
        if ($this->getReinvitedCount() < $this->getMaxReinvitations()) {
            $this->setNextReinvited($date->add(new \DateInterval('P1D')));
        } else {
            $this->setNextReinvited();
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

    public function isInviteeProcessed()
    {
        return $this->isAccepted() || $this->isRejected();
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

    public function getInviterName()
    {
        if ($this->getInviter()) {
            return $this->getInviter()->getName();
        } else {
            return null;
        }
    }

    public function getInviteeImageUrl($size = 100)
    {
        if ($this->getInvitee()) {
            return $this->getInvitee()->getImageUrl();
        }

        if ($this instanceof EmailInvitation) {
            return $this->gravatarImage($this->getEmail(), $size);
        }

        return null;
    }

    public function getInviteeImageUrlFallback($size = 100)
    {
        if ($this->getInvitee()) {
            return $this->getInvitee()->getImageUrlFallback($size);
        }

        if ($this instanceof EmailInvitation) {
            $initial = mb_strtolower($this->getEmail()[0]);

            return $this->gravatarImageFallback(
                $this->getEmail(),
                $size,
                sprintf('https://cdn.so-sure.com/images/alpha/%s.png', $initial)
            );
        }

        return null;
    }

    public function getInviterImageUrl($size = 100)
    {
        if ($this->getInviter()) {
            return $this->getInviter()->getImageUrl();
        }

        if ($this instanceof EmailInvitation) {
            return $this->gravatarImage($this->getInviter()->getEmail(), $size);
        }

        return null;
    }

    public function getInviterFacebookId()
    {
        return $this->getInviter() ? $this->getInviter()->getFacebookId() : null;
    }

    public function getInviteeFacebookId()
    {
        return $this->getInvitee() ? $this->getInvitee()->getFacebookId() : null;
    }

    public function toApiArray($isReceivedInvitation = false, $debug = null)
    {
        $data = [
            'id' => $this->getId(),
            'name' => $this->getName() ? $this->getName() : null,
            'invitation_detail' => $this->getInvitationDetail(),
            'inviter_name' => $this->getInviterName(),
            'channel' => $this->getChannel(),
            'link' => $this->getLink(),
            'status' => $this->getStatus(),
            'created_date' => $this->getCreated() ? $this->getCreated()->format(\DateTime::ATOM) : null,
            'next_reinvite_date' =>  $this->getNextReinvited() ?
                $this->getNextReinvited()->format(\DateTime::ATOM) :
                null,
        ];

        if ($isReceivedInvitation) {
            $data['image_url'] = $this->getInviterImageUrl();
        } else {
            $data['image_url'] = $this->getInviteeImageUrl();
        }

        $data['channel_details'] = $this->getChannelDetails();
        $data['policy_id'] = $this->getPolicy() ? $this->getPolicy()->getId() : null;

        if ($debug) {
            $data = array_merge($data, [
                'inviter_id' => $this->getInviter() ? $this->getInviter()->getId() : null,
                'invitee_id' => $this->getInvitee() ? $this->getInvitee()->getId() : null,
                'invitee_name' => $this->getInviteeName(),
            ]);
        }

        return $data;
    }
}
