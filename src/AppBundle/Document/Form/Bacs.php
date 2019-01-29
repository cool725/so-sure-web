<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\BankAccount;
use AppBundle\Document\Address;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BacsTrait;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Bacs extends BankAccount
{
    use BacsTrait;

    /**
     * @Assert\Type("bool")
     * @Assert\IsTrue(message="You must be the sole signature on the account to setup a direct debit")
     */
    protected $soleSignature;

    /**
     * @AppAssert\Token()
     * @var string
     */
    protected $validateName;

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
