<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Connection\Connection;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\RewardRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Reward
{
    use CurrencyTrait;

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     */
    protected $user;

    /**
     * @MongoDB\ReferenceOne(targetDocument="SCode")
     * @Gedmo\Versioned
     */
    protected $scode;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Connection\RewardConnection")
     */
    protected $connections = array();

    /**
     * @MongoDB\Field(type="float", nullable=false)
     * @Gedmo\Versioned
     */
    protected $potValue;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $defaultValue;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $expiryDate;

    /**
     * @MongoDB\Field(type="int")
     * @Gedmo\Versioned
     */
    protected $policyAgeMin;

    /**
     * @MongoDB\Field(type="int")
     * @Gedmo\Versioned
     */
    protected $policyAgeMax;

    /**
     * @Assert\Range(min=0,max=2000)
     * @MongoDB\Field(type="float")
     */
    protected $usageLimit;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $hasNotClaimed;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $hasRenewed;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $hasCancelled;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $isFirst;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $isSignUpBonus;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $isConnectionBonus;

    /**
     * @Assert\Length(min="50", max="1000")
     * @MongoDB\Field(type="string")
     */
    protected $termsAndConditions;

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function getSCode()
    {
        return $this->scode;
    }

    public function setSCode($scode)
    {
        $this->scode = $scode;
    }

    public function getConnections()
    {
        return $this->connections;
    }

    public function addConnection(Connection $connection)
    {
        $this->connections[] = $connection;
    }

    public function getPotValue()
    {
        return $this->toTwoDp($this->potValue);
    }

    public function setPotValue($potValue)
    {
        $this->potValue = $potValue;
    }

    public function getDefaultValue()
    {
        return $this->toTwoDp($this->defaultValue);
    }

    public function setDefaultValue($defaultValue)
    {
        $this->defaultValue = $defaultValue;
    }

    public function getExpiryDate()
    {
        return $this->expiryDate;
    }

    public function setExpiryDate($expiryDate)
    {
        $this->expiryDate = $expiryDate;
    }

    public function getPolicyAgeMin()
    {
        return $this->policyAgeMin;
    }

    public function setPolicyAgeMin($policyAgeMin)
    {
        $this->policyAgeMin = $policyAgeMin;
    }

    public function getPolicyAgeMax()
    {
        return $this->policyAgeMax;
    }

    public function setPolicyAgeMax($policyAgeMax)
    {
        $this->policyAgeMax = $policyAgeMax;
    }

    public function getUsageLimit()
    {
        return $this->usageLimit;
    }

    public function setUsageLimit($usageLimit)
    {
        $this->usageLimit = $usageLimit;
    }

    public function getHasNotClaimed()
    {
        return $this->hasNotClaimed;
    }

    public function setHasNotClaimed($hasNotClaimed)
    {
        $this->hasNotClaimed = $hasNotClaimed;
    }

    public function getHasRenewed()
    {
        return $this->hasRenewed;
    }

    public function setHasRenewed($hasRenewed)
    {
        $this->hasRenewed = $hasRenewed;
    }

    public function getHasCancelled()
    {
        return $this->hasCancelled;
    }

    public function setHasCancelled($hasCancelled)
    {
        $this->hasCancelled = $hasCancelled;
    }

    public function getIsFirst()
    {
        return $this->isFirst;
    }

    public function setIsFirst($isFirst)
    {
        $this->isFirst = $isFirst;
    }

    /**
     * @return mixed
     */
    public function getIsSignUpBonus()
    {
        return $this->isSignUpBonus;
    }

    /**
     * @param mixed $isSignUpBonus
     * @return Reward
     */
    public function setIsSignUpBonus($isSignUpBonus)
    {
        $this->isSignUpBonus = $isSignUpBonus;
        return $this;
    }
    
    /**
     * Gives whether or not this reward is a connection bonus.
     * @return boolean|null whether or not it's a connection bonus or null if unset.
     */
    public function getIsConnectionBonus()
    {
        return $this->isConnectionBonus();
    }

    /**
     * Sets whether or not this is a connection bonus.
     * @param boolean $isConnectionBonus is whether or not it is a connection bonus.
     */
    public function setIsConnectionBonus($isConnectionBonus)
    {
        $this->isConnectionBonus = $isConnectionBonus;
    }

    public function getTermsAndConditions()
    {
        return $this->termsAndConditions;
    }

    public function setTermsAndConditions($termsAndConditions)
    {
        $this->termsAndConditions = $termsAndConditions;
    }

    public function updatePotValue()
    {
        $this->setPotValue($this->calculatePotValue());
    }

    public function calculatePotValue($promoValueOnly = false)
    {
        $potValue = 0;
        // TODO: How does a cancelled policy affect networked connections?  Would the connection be withdrawn?
        foreach ($this->connections as $connection) {
            if ($promoValueOnly) {
                $potValue += $connection->getPromoValue();
            } else {
                $potValue += $connection->getTotalValue();
            }
        }

        return $potValue;
    }

    /**
     * Tells if this reward is still going. It can stop either because the date has gone past the reward's expiry date,
     * or because it has hit the maximum number of users it can have.
     * @param \DateTime $date is the date at which we are checking.
     */
    public function isOpen(\DateTime $date)
    {
        return (!$this->getExpiryDate() || $this->getExpiryDate() > $date) &&
            (!$this->getUsageLimit() || count($this->getConnections()) < $this->getUsageLimit());
    }

    /**
     * Tells you if a policy can get this reward.
     * @param Policy    $policy is the policy to check about.
     * @param \DateTime $date   is the date of potential application.
     * @return boolean true if the reward could be applied, and false if not.
     */
    public function canApply($policy, \DateTime $date)
    {
        if (!$this->isOpen($date)) {
            return false;
        }
        $min = $this->getPolicyAgeMin();
        $max = $this->getPolicyAgeMax();
        $age = $policy->age();
        if (($min && $age < $min) || ($max && $age > $max)) {
            return false;
        }
        $user = $policy->getUser();
        if (!$user) {
            return false;
        }
        $notClaimed = $user->policyReduce(true, function ($current, $next) {
            return $current && count($next->getClaims()) == 0;
        });
        $renewed = $user->hasRenewalPolicy();
        $cancelled = $user->hasCancelledPolicy();
        $first = $user->policyReduce(true, function ($current, $next) use ($policy) {
            return $current && $policy->getStart() <= $next->getStart();
        });
        if (($this->getHasNotClaimed() && !$notClaimed) || ($this->getHasRenewed() && !$renewed) ||
            ($this->getHasCancelled() && !$cancelled) || ($this->getIsFirst() && !$first)) {
            return false;
        }
        return true;
    }
}
