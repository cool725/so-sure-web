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
    const STATUS_ACTIVE = "active";
    const STATUS_COMPLETED = "completed";
    const STATUS_FAILED = "failed";
    const STATUS_INVALID = "invalid";
    const INVALID_EXISTING_TASTE_CARD = "existing-taste-card";

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
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $end;

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

    public function setPromotion(Promotion $promotion)
    {
        $this->promotion = $promotion;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
    }

    public function getStart()
    {
        return $this->start;
    }

    public function setStart(\DateTime $start)
    {
        $this->start = $start;
    }

    public function getEnd()
    {
        return $this->end;
    }

    public function setEnd(\DateTime $end)
    {
        $this->end = $end;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    /**
     * Sets the end time and the status at the same time.
     * @param String    $status is the status that the participation ended with.
     * @param \DateTime $date   is the date of the participation's ending.
     */
    public function endWithStatus($status, $date)
    {
        $this->status = $status;
        $this->end = clone $date;
    }
}
