<?php

namespace AppBundle\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Represents a policy's participation in a promotion.
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ParticipationRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Participation
{
    // Participation is in progress.
    const STATUS_ACTIVE = "active";
    // Promotion conditions were fulfilled and reward given.
    const STATUS_COMPLETED = "completed";
    // Reward could not be given.
    const STATUS_INVALID = "invalid";
    // Promotion conditions were not fulfilled and reward was not given.
    const STATUS_FAILED = "failed";

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Promotion", inversedBy="participators")
     * @Gedmo\Versioned
     */
    protected $promotion;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy", inversedBy="participations")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $policy;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $start;

    /**
     * Stores the condition requied for a policy to fulfill the promotion and receive a reward.
     * @Assert\Choice({"active", "completed", "invalid", "failed"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getPromotion()
    {
        return $this->promotion;
    }

    public function setPromotion($promotion)
    {
        $this->promotion = $promotion;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    public function getStart()
    {
        return $start;
    }

    public function setStart($start)
    {
        $this->start = $start;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }


}
