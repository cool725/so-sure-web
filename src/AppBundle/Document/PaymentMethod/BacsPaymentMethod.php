<?php

namespace AppBundle\Document\PaymentMethod;

use AppBundle\Document\BankAccount;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/** @MongoDB\EmbeddedDocument */
class BacsPaymentMethod extends PaymentMethod
{
    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\BankAccount")
     * @Gedmo\Versioned
     * @var BankAccount
     */
    protected $bankAccount;

    /**
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\BankAccount")
     */
    protected $previousBankAccounts = [];

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $soleSignature;

    /**
     * @param BankAccount $bankAccount
     */
    public function setBankAccount(BankAccount $bankAccount)
    {
        $this->bankAccount = $bankAccount;
        $this->addPreviousBankAccount($bankAccount);
    }

    /**
     * @return BankAccount
     */
    public function getBankAccount()
    {
        return $this->bankAccount;
    }

    public function getPreviousBankAccounts()
    {
        return $this->previousBankAccounts;
    }

    public function addPreviousBankAccount(BankAccount $bankAccount)
    {
        $prev = $this->previousBankAccounts;
        if (is_object($this->previousBankAccounts)) {
            /** @var ArrayCollection $prevCollection */
            $prevCollection = $this->previousBankAccounts;
            $prev = $prevCollection->toArray();
        }
        if (!in_array($bankAccount, $prev)) {
            $this->previousBankAccounts[] = $bankAccount;
        }
    }

    public function setSoleSignature($soleSignature)
    {
        $this->soleSignature = filter_var($soleSignature, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    public function getSoleSignature()
    {
        return $this->soleSignature;
    }

    public function isValid()
    {
        return $this->getBankAccount() ? $this->getBankAccount()->isValid() : false;
    }

    public function __toString()
    {
        return $this->getBankAccount() ? $this->getBankAccount()->__toString() : '';
    }
}
