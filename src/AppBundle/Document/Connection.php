<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ConnectionRepository")
 * @Gedmo\Loggable
 */
class Connection
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     */
    protected $linkedUser;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     */
    protected $sourceUser;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy")
     * @Gedmo\Versioned
     */
    protected $sourcePolicy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy")
     * @Gedmo\Versioned
     */
    protected $linkedPolicy;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $value;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $promoValue;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $initialValue;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $initialPromoValue;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     */
    protected $replacementUser;

    public function __construct()
    {
        $this->date = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getLinkedUser()
    {
        return $this->linkedUser;
    }

    public function setLinkedUser(User $user)
    {
        $this->linkedUser = $user;
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
                if ($claim->getPolicy()->getId() == $this->getLinkedPolicy()->getId() && $claim->getClosedDate()) {
                    $claimDates[] =  $claim->getClosedDate()->format(\DateTime::ATOM);
                }
            }
        }

        return [
            'name' => $this->getLinkedUser() ? $this->getLinkedUser()->getName() : null,
            'date' => $this->getDate() ? $this->getDate()->format(\DateTime::ATOM) : null,
            'claim_dates' => $claimDates,
        ];
    }
}
