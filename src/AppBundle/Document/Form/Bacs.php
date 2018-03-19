<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\BankAccount;
use AppBundle\Document\Address;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BacsTrait;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Bacs extends BankAccount
{
    use BacsTrait;

    /**
     * @Assert\Type("bool")
     * @Assert\IsTrue
     */
    protected $soleSignature;

    public function setSoleSignature($soleSignature)
    {
        $this->soleSignature = filter_var($soleSignature, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function getSoleSignature()
    {
        return $this->soleSignature;
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

    public function transformBacsPaymentMethod()
    {
        $bacsPaymentMethod = new BacsPaymentMethod();
        $bacsPaymentMethod->setBankAccount($this->toBankAccount());

        return $bacsPaymentMethod;
    }

    public function toBankAccount()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setBankName($this->getBankName());
        $bankAccount->setAccountNumber($this->getAccountNumber());
        $bankAccount->setSortCode($this->getSortCode());
        $bankAccount->setAccountName($this->getAccountName());
        $bankAccount->setBankAddress($this->getBankAddress());
        $bankAccount->setReference($this->getReference());

        return $bankAccount;
    }
}
