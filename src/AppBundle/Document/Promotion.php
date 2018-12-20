<?php

namespace AppBundle\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Represents a promotion which allows selected policies to earn a reward under some condition.
 * @MongoDB\Document
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Promotion
{
    const REWARD_TASTE_CARD = "taste-card";
    const REWARD_POT = "pot";
    const REWARD_PHONE_CASE = "phone-case";
    const REWARDS = [
        "Taste Card" => self::REWARD_TASTE_CARD,
        "Reward Pot Balance" => self::REWARD_POT,
        "Free Phone Case" => self::REWARD_PHONE_CASE
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="150")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $name;

    /**
     * Stores the date at which the promotion was created.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $start;

    /**
     * Stores the date at which the promotion was made inactive.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $end;

    /**
     * Stores whether the promotion is considered ongoing or concluded.
     * @MongoDB\Field(type="boolean")
     */
    protected $active;

    /**
     * Stores which type of reward this promotion yields when completed successfully.
     * @Assert\Choice({"taste-card", "pot", "phone-case"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $reward;

    /**
     * Stores list all participations regardless of activity status.
     * @MongoDB\ReferenceMany(targetDocument="Participation", mappedBy="promotion")
     */
    protected $participating;

    /**
     * Period of time that promotion participation must take.
     * @Assert\Range(min=0,max=90)
     * @MongoDB\Field(type="integer")
     */
    protected $conditionPeriod;

    /**
     * Number of invitations required to complete the promotion.
     * @Assert\Range(min=0,max=90)
     * @MongoDB\Field(type="integer")
     */
    protected $conditionInvitations;

    /**
     * Whether or not a participating policy is allowed to claim to successfully complete this promotion.
     * @MongoDB\Field(type="boolean")
     */
    protected $conditionAllowClaims;

    /**
     * If reward has a quanitity (eg amount of money added to reward pot) this stores that quantity.
     * @Assert\Range(min=0,max=100)
     * @MongoDB\Field(type="float")
     */
    protected $rewardAmount;

    /**
     * Stores list of all affiliates currently linked to this promotion.
     * @MongoDB\ReferenceMany(targetDocument="AffiliateCompany", mappedBy="promotion")
     */
    protected $affiliates;

    /**
     * Builds the promotion's participation list.
     */
    public function __construct()
    {
        $this->participating = new ArrayCollection();
        $this->affiliates = new ArrayCollection();
        $this->notesList = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getStart()
    {
        return $this->start;
    }

    public function setStart($start)
    {
        $this->start = $start;
    }

    public function getActive()
    {
        return $this->active;
    }

    public function setActive($active)
    {
        $this->active = $active;
        if (!$active) {
            $this->end = new \DateTime();
        }
    }

    public function getReward()
    {
        return $this->reward;
    }

    public function setReward($reward)
    {
        $this->reward = $reward;
    }

    public function getParticipating()
    {
        return $this->participating;
    }

    public function addParticipating($participation)
    {
        if (!$this->active) {
            throw new \Exception("Attempted to add participation to inactive promotion.");
        }
        $this->participating[] = $participation;
        $participation->setPromotion($this);
    }

    public function getAffiliates()
    {
        return $this->affiliates;
    }

    public function addAffiliates($affiliate)
    {
        $this->affiliates[] = $affiliate;
        $affiliate->setPromotion($this);
    }

    public function getConditionPeriod()
    {
        return $this->conditionPeriod;
    }

    public function setConditionPeriod($conditionPeriod)
    {
        $this->conditionPeriod = $conditionPeriod;
    }

    public function getConditionInvitations()
    {
        return $this->conditionInvitations;
    }

    public function setConditionInvitations($conditionInvitations)
    {
        $this->conditionInvitations = $conditionInvitations;
    }

    public function getConditionAllowClaims()
    {
        return $this->conditionAllowClaims;
    }

    public function setConditionAllowClaims($conditionAllowClaims)
    {
        $this->conditionAllowClaims = $conditionAllowClaims;
    }

    public function getRewardAmount()
    {
        return $this->rewardAmount;
    }

    public function setRewardAmount($rewardAmount)
    {
        $this->rewardAmount = $rewardAmount;
    }
}
