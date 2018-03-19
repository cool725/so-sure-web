<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/** @MongoDB\EmbeddedDocument */
class BacsPaymentMethod extends PaymentMethod
{
    /**
     * @MongoDB\EmbedOne(targetDocument="BankAccount")
     * @Gedmo\Versioned
     */
    protected $bankAccount;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $soleSignature;

    public function setBankAccount(BankAccount $bankAccount)
    {
        $this->bankAccount = $bankAccount;
    }

    public function getBankAccount()
    {
        return $this->bankAccount;
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
