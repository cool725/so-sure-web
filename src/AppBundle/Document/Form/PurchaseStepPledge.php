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

    protected $agreedDamage;

    protected $agreedAgeLocation;

    protected $agreedTerms;

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

    public function getagreedAgeLocation()
    {
        return $this->agreedAgeLocation;
    }

    public function setAgreedAgeLocation($agreedAgeLocation)
    {
        $this->agreedAgeLocation = $agreedAgeLocation;
    }

    public function getAgreedTerms()
    {
        return $this->agreedTerms;
    }

    public function setAgreedTerms($agreedTerms)
    {
        $this->agreedTerms = $agreedTerms;
    }
}
