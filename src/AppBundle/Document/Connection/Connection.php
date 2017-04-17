<?php

namespace AppBundle\Document\Connection;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\CurrencyTrait;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ConnectionRepository")
 * @Gedmo\Loggable
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"standard"="StandardConnection", "reward"="RewardConnection"})
 * @MongoDB\Index(keys={"sourcePolicy.id"="asc"}, sparse="true")
 * @MongoDB\Index(keys={"linkedPolicy.id"="asc"}, sparse="true")
 */
class Connection
{
    use CurrencyTrait;

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     * @Gedmo\Versioned
     */
    protected $linkedUser;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     * @Gedmo\Versioned
     */
    protected $sourceUser;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     */
    protected $sourcePolicy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Invitation\Invitation", cascade={"persist"})
     * @Gedmo\Versioned
     */
    protected $invitation;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $initialInvitationDate;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     */
    protected $linkedPolicy;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $value;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $promoValue;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $initialValue;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $initialPromoValue;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User")
     * @Gedmo\Versioned
     */
    protected $replacementUser;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $excludeReporting;

    public function __construct()
    {
        $this->date = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getLinkedUser()
    {
        return $this->linkedUser;
    }

    public function setLinkedUser(User $user)
    {
        $this->linkedUser = $user;
    }

    public function getInvitation()
    {
        return $this->invitation;
    }

    public function setInvitation(Invitation $invitation)
    {
        $this->invitation = $invitation;
    }

    public function getInitialInvitationDate()
    {
        return $this->initialInvitationDate;
    }

    public function setInitialInvitationDate(\DateTime $initialInvitationDate)
    {
        $this->initialInvitationDate = $initialInvitationDate;
    }

    public function getSourceUser()
    {
        return $this->sourceUser;
    }

    public function setSourceUser(User $user)
    {
        $this->sourceUser = $user;
    }

    public function getSourcePolicy()
    {
        return $this->sourcePolicy;
    }

    public function setSourcePolicy(Policy $policy)
    {
        if ($this->getId() && $this->getLinkedPolicy() && $this->getLinkedPolicy()->getId() == $policy->getId()) {
            throw new \Exception('Policy can not be linked to itself');
        }

        $this->sourcePolicy = $policy;
    }

    public function getLinkedPolicy()
    {
        return $this->linkedPolicy;
    }

    public function setLinkedPolicy(Policy $policy)
    {
        if ($this->getId() && $this->getSourcePolicy() && $this->getSourcePolicy()->getId() == $policy->getId()) {
            throw new \Exception('Policy can not be linked to itself');
        }

        $this->linkedPolicy = $policy;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate($date)
    {
        $this->date = $date;
    }

    public function getValue()
    {
        return $this->value ? $this->value : 0;
    }

    public function setValue($value)
    {
        $this->value = $value;
        if (!$this->getInitialValue()) {
            $this->initialValue = $value;
        }
    }

    public function getPromoValue()
    {
        return $this->promoValue ? $this->promoValue : 0;
    }

    public function setPromoValue($promoValue)
    {
        $this->promoValue = $promoValue;
        if (!$this->getInitialPromoValue()) {
            $this->initialPromoValue = $promoValue;
        }
    }

    public function getTotalValue()
    {
        return $this->getValue() + $this->getPromoValue();
    }

    public function setExcludeReporting($excludeReporting)
    {
        $this->excludeReporting = $excludeReporting;
    }

    public function getExcludeReporting()
    {
        return $this->excludeReporting;
    }

    public function clearValue()
    {
        $this->value = 0;
        $this->promoValue = 0;
    }

    public function getInitialValue()
    {
        return $this->initialValue;
    }

    public function getInitialPromoValue()
    {
        return $this->initialPromoValue;
    }

    public function getReplacementUser()
    {
        return $this->replacementUser;
    }

    public function setReplacementUser($replacementUser)
    {
        $this->replacementUser = $replacementUser;
    }

    public function toApiArray($claims)
    {
        $claimDates = [];
        if ($claims) {
            foreach ($claims as $claim) {
                if ($this->getLinkedPolicy() &&
                    $claim->getPolicy()->getId() == $this->getLinkedPolicy()->getId() &&
                    $claim->getClosedDate()) {
                    $claimDates[] =  $claim->getClosedDate()->format(\DateTime::ATOM);
                }
            }
        }

        return [
            'name' => $this->getLinkedUser() ? $this->getLinkedUser()->getName() : null,
            'date' => $this->getDate() ? $this->getDate()->format(\DateTime::ATOM) : null,
            'claim_dates' => $claimDates,
            'id' => $this->getId(),
            'image_url' => $this->getLinkedUser() ? $this->getLinkedUser()->getImageUrl() : null,
            'value' => $this->toTwoDp($this->getTotalValue()),
            'facebook_id' => $this->getLinkedUser() ? $this->getLinkedUser()->getFacebookId() : null,
        ];
    }
}
