<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\File\ImeiUploadFile;

class PurchaseStepPledge
{
    use CurrencyTrait;
    use PhoneTrait;

    /** @var Policy */
    protected $policy;

    /** @var User */
    protected $user;

    /**
     * @var boolean
     * @Assert\IsTrue(message="You must confirm that your phone is in working condition and the screen is not cracked")
     */
    protected $agreedDamage;

    /**
     * @var boolean
     * @Assert\IsTrue(message="You must confirm that you are a UK resident and over the age of 18")
     */
    protected $agreedAgeLocation;

    /**
     * @var boolean
     * @Assert\IsTrue(message="You must confirm that you understand our excess policy")
     */
    protected $agreedExcess;

    /**
     * @var boolean
     * @Assert\IsTrue(message="You must agree to our terms")
     */
    protected $agreedTerms;

    /**
     * @var boolean
     */
    protected $userOptIn;

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy(Policy $policy)
    {
        $this->policy = $policy;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser($user)
    {
        $this->user = $user;
    }

    public function getAgreedDamage()
    {
        return $this->agreedDamage;
    }

    public function setAgreedDamage($agreedDamage)
    {
        $this->agreedDamage = $agreedDamage;
    }

    public function getAgreedAgeLocation()
    {
        return $this->agreedAgeLocation;
    }

    public function setAgreedAgeLocation($agreedAgeLocation)
    {
        $this->agreedAgeLocation = $agreedAgeLocation;
    }

    public function getAgreedExcess()
    {
        return $this->agreedExcess;
    }

    public function setAgreedExcess($agreedExcess)
    {
        $this->agreedExcess = $agreedExcess;
    }

    public function getAgreedTerms()
    {
        return $this->agreedTerms;
    }

    public function setAgreedTerms($agreedTerms)
    {
        $this->agreedTerms = $agreedTerms;
    }

    public function getUserOptIn()
    {
        return $this->userOptIn;
    }

    public function setUserOptIn($userOptIn)
    {
        $this->userOptIn = $userOptIn;
    }

    public function areAllAgreed()
    {
        return $this->getAgreedAgeLocation() && $this->getAgreedDamage()
        && $this->getAgreedExcess() && $this->getAgreedTerms();
    }
}
