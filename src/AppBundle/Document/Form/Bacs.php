<?php

namespace AppBundle\Document\Form;

use AppBundle\Document\BankAccount;
use AppBundle\Document\BacsPaymentMethod;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

class Bacs
{
    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     */
    protected $accountName;
    
    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="50")
     */
    protected $sortCode;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="50")
     */
    protected $accountNumber;

    /**
     * @Assert\Type("bool")
     */
    protected $soleSignature;

    /**
     */
    protected $bankAddress;

    public function setAccountName($accountName)
    {
        $this->accountName = $accountName;
    }

    public function getAccountName()
    {
        return $this->accountName;
    }

    public function setSortCode($sortCode)
    {
        $this->sortCode = $sortCode;
    }

    public function getSortCode()
    {
        return $this->sortCode;
    }

    public function setAccountNumber($accountNumber)
    {
        $this->accountNumber = $accountNumber;
    }

    public function getAccountNumber()
    {
        return $this->accountNumber;
    }

    public function setBankAddress(Address $address)
    {
        $this->bankAddress = $address;
    }

    public function getBankAddress()
    {
        return $this->bankAddress;
    }

    public function setSoleSignature($soleSignature)
    {
        $this->soleSignature = filter_var($soleSignature, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function getSoleSignature()
    {
        return $this->soleSignature;
    }

    public function transformBankAccount()
    {
        $bankAccount = new BankAccount();
        $bankAccount->setAccountName($this->getAccountName());
        $bankAccount->setAccountNumber($this->getAccountNumber());
        $bankAccount->setSortCode($this->getSortCode());

        return $bankAccount;
    }

    public function transformBacsPaymentMethod()
    {
        $bacsPaymentMethod = new BacsPaymentMethod();
        $bacsPaymentMethod->setBankAccount($this->transformBankAccount());

        return $bacsPaymentMethod;
    }

    public function isValid()
    {
        return $this->transformBankAccount()->isValid();
    }
    
    public function getDisplayableAccountNumber()
    {
        return $this->transformBankAccount()->getDisplayableAccountNumber();        
    }
}
