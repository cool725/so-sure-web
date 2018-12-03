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
    const CONDITION_NO_CLAIMS = "no-claims";
    const CONDITION_INVITES = "invites";
    const CONDITION_NONE = "none";
    const CONDITIONS = [
        "No Claims in period" => self::CONDITION_NO_CLAIMS,
        "Make x Invitations" => self::CONDITION_INVITES,
        "Reward automatically" => self::CONDITION_NONE
    ];
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
     * Stores the condition requied for a policy to fulfill the promotion and receive a reward.
     * @Assert\Choice({"no-claims", "invites", "none"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $condition;

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
     * Maximum number of days over which a policy can be active within the promotion.
     * @Assert\Range(min=0,max=90)
     * @MongoDB\Field(type="integer")
     */
    protected $period;

    /**
     * If condition requires a number of events to occur (eg invites) this stores that number.
     * @Assert\Range(min=1,max=90)
     * @MongoDB\Field(type="integer")
     */
    protected $conditionEvents;

    /**
     * If reward has a quanitity (eg amount of money added to reward pot) this stores that quantity.
     * @Assert\Range(min=0,max=100)
     * @MongoDB\Field(type="float")
     */
    protected $rewardAmount;

    /**
     * Builds the promotion's participation list.
     */
    public function __construct()
    {
        $this->participating = new ArrayCollection();
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

    public function getCondition()
    {
        return $this->condition;
    }

    public function setCondition($condition)
    {
        $this->condition = $condition;
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
        $this->participating[] = $participation;
        $participation->setPromotion($this);
    }

    public function getPeriod()
    {
        return $this->period;
    }

    public function setPeriod($period)
    {
        $this->period = $period;
    }

    public function getConditionEvents()
    {
        return $this->conditionEvents;
    }

    public function setConditionEvents($conditionEvents)
    {
        $this->conditionEvents = $conditionEvents;
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
