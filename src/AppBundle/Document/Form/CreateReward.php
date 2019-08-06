<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\User;
use AppBundle\Document\Reward;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represents creating a reward which entails the creation of both user and reward records, and so this stores the
 * details required to do both.
 */
class CreateReward
{
    /**
     * @var string
     * @Assert\NotNull(message="Must provide first name for reward user")
     */
    protected $firstName;

    /**
     * @var string
     * @Assert\NotNull(message="Must provide last name for reward user")
     */
    protected $lastName;

    /**
     * @var string
     * @Assert\NotNull(message="Must provide email for reward user")
     */
    protected $email;

    /**
     * @var string
     * @Assert\NotNull(message="Must provide Scode for reward")
     */
    protected $code;

    /**
     * @var float
     */
    protected $defaultValue;

    /**
     * @var \DateTime
     * @Assert\NotNull(message="Must provide expiry date for reward")
     */
    protected $expiryDate;

    /**
     * @var int
     */
    protected $policyAgeMin;

    /**
     * @var int
     */
    protected $policyAgeMax;

    /**
     * @var int
     */
    protected $usageLimit;

    /**
     * @var boolean
     */
    protected $hasNotClaimed;

    /**
     * @var boolean
     */
    protected $hasRenewed;

    /**
     * @var boolean
     */
    protected $hasCancelled;

    /**
     * @var boolean
     */
    protected $isFirst;

    /**
     * @var boolean
     */
    protected $termsAndConditions;

    public function getFirstName()
    {
        return $this->firstName;
    }

    public function setFirstName($firstName)
    {
        $this->firstName = $firstName;
    }

    public function getLastName()
    {
        return $this->lastName;
    }

    public function setLastName($lastName)
    {
        $this->lastName = $lastName;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = $email;
    }

    public function getCode()
    {
        return $this->code;
    }

    public function setCode($code)
    {
        $this->code = $code;
    }

    public function getDefaultValue()
    {
        return $this->defaultValue;
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

    public function getTermsAndConditions()
    {
        return $this->termsAndConditions;
    }

    public function setTermsAndConditions($termsAndConditions)
    {
        $this->termsAndConditions = $termsAndConditions;
    }
}
