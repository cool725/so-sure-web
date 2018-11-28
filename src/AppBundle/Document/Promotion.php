<?php

namespace AppBundle\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Represents a promotion which allows selected policies to earn a reward under some condition.
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PromotionRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Promotion
{
    // Make no claims for conditionDays days to receive reward.
    const CONDITION_NO_CLAIMS = "no-claims";
    // Make conditionEvents invitations within conditionDays days to receive reward.
    const CONDITION_INVITES = "invites";
    // Don't cancel your policy for conditionDays days to receive reward.
    const CONDITION_NONE = "none";

    // Assign a taste card to the policy as a reward.
    const REWARD_TASTE_CARD = "taste-card";
    // Add balance to the reward pot as a reward.
    const REWARD_POT = "pot";
    // Send the policy holder a phone case as a reward.
    const REWARD_PHONE_CASE = "phone-case";

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
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $start;

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
    protected $conditionDays;

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
        parent::__construct();
        $this->confirmedUsers = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = id;

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
    }

    public function getCondition()
    {
        return $this->active;
    }

    public function setCondition($active)
    {
        $this->activec = $active;
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

    public function getConditionDays()
    {
        return $this->conditionDays;
    }

    public function setConditionDays($conditionDays)
    {
        $this->conditionDays = $conditionDays;
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
