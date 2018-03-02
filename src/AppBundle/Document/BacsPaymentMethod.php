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
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $accountName;
    
    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $sortCode;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $accountNumber;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $soleSignature;    
    
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

    public function setSoleSignature($soleSignature)
    {
        $this->soleSignature = $soleSignature;
    }

    public function getSoleSignature()
    {
        return $this->soleSignature;
    }

    public function isValid()
    {
        // TODO: Fix me
        return true;
    }

    public function getDisplayableAccountNumber()
    {
        if ($this->getAccountNumber() && strlen($this->getAccountNumber()) == 8) {
            return sprintf("XXXX%s", substr($this->getAccountNumber(), 4, 4));
        }

        return null;
    }

    public function __toString()
    {
        return sprintf("%s %s %s", $this->getAccountName(), $this->getSortCode(), $this->getDisplayableAccountNumber());
    }
}
