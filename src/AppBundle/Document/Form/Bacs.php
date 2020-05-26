<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\DateTrait;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Address;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BacsTrait;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Bacs extends BankAccount
{
    use BacsTrait;
    use DateTrait;

    /**
     * @Assert\Type("bool")
     * @Assert\IsTrue(message="You must be the sole signature on the account to setup a direct debit")
     */
    protected $soleSignature;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @AppAssert\Token()
     * @var string
     */
    protected $validateName;

    /**
     * @Assert\Range(min="1", max="28")
     */
    protected $billingDate;

    public function setSoleSignature($soleSignature)
    {
        $this->soleSignature = filter_var($soleSignature, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function getSoleSignature()
    {
        return $this->soleSignature;
    }

    public function setValidateName($validateName)
    {
        $this->validateName = $validateName;
    }

    public function getValidateName()
    {
        return $this->validateName;
    }

    public function setBillingDate($billingDate)
    {
        $this->billingDate = $billingDate;
    }

    public function getBillingDate()
    {
        return $this->billingDate;
    }

    /**
     * The stored billing date value is only an integer so this converts it to a day of the month.
     * @return \DateTime the day of the month as a date in the current month.
     */
    public function getCalculatedBillingDate()
    {
        return DateTrait::setDayOfMonth($this->startOfMonth(), $this->billingDate);
    }

    public function setBankAccount(BankAccount $bankAccount = null)
    {
        if ($bankAccount) {
            $this->setBankAddress($bankAccount->getBankAddress());
            $this->setSortCode($bankAccount->getSortCode());
            $this->setAccountNumber($bankAccount->getAccountNumber());
            $this->setBankName($bankAccount->getBankName());
        }
    }

    public function transformBacsPaymentMethod(IdentityLog $identityLog = null)
    {
        $bacsPaymentMethod = new BacsPaymentMethod();
        $bacsPaymentMethod->setBankAccount($this->toBankAccount($identityLog));

        return $bacsPaymentMethod;
    }

    public function toBankAccount(IdentityLog $identityLog = null)
    {
        $bankAccount = new BankAccount();
        $bankAccount->setBankName($this->getBankName());
        $bankAccount->setAccountNumber($this->getAccountNumber());
        $bankAccount->setSortCode($this->getSortCode());
        $bankAccount->setAccountName($this->getAccountName());
        $bankAccount->setBankAddress($this->getBankAddress());
        $bankAccount->setReference($this->getReference());
        $bankAccount->setIdentityLog($identityLog);
        $bankAccount->setAnnual($this->isAnnual());

        return $bankAccount;
    }
}
