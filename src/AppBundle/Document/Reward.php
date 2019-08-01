<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Connection\Connection;

/**
 * @MongoDB\Document()
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
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $policyAgeMin;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
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
    protected $hasClaimed;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     */
    protected $hasRenewed;

    /**
     * @Assert\Length(min="50", max="1000")
     */
    protected $termsAndConditions;

    public function __construct()
    {
    }

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

    public function getHasClaimed()
    {
        return $this->hasClaimed;
    }

    public function setHasClaimed($hasClaimed)
    {
        $this->hasClaimed = $hasClaimed;
    }

    public function getHasRenewed()
    {
        return $this->hasRenewed;
    }

    public function setHasRenewed($hasRenewed)
    {
        $this->hasRenewed = $hasRenewed;
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
}
