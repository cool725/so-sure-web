<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use VasilDakov\Postcode\Postcode;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable
 */
class BankAccount
{
    use BacsTrait;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $accountName;
    
    /**
     * @AppAssert\SortCode()
     * @MongoDB\Field(type="string")
     * @Assert\Length(min="6", max="6")
     * @Gedmo\Versioned
     */
    protected $sortCode;

    /**
     * @AppAssert\Token()
     * @AppAssert\BankAccountNumber()
     * @MongoDB\Field(type="string")
     * @Assert\Length(min="8", max="8")
     * @Gedmo\Versioned
     */
    protected $accountNumber;

    /**
     * @MongoDB\EmbedOne(targetDocument="Address")
     * @Gedmo\Versioned
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
        $this->sortCode = $this->normalizeSortCode($sortCode);
    }

    public function getSortCode()
    {
        return $this->sortCode;
    }

    public function setAccountNumber($accountNumber)
    {
        $this->accountNumber = $this->normalizeAccountNumber($accountNumber);
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

    public function isValid()
    {
        return strlen($this->getAccountName()) > 2 && strlen($this->getAccountNumber()) >= 6 &&
            strlen($this->getAccountNumber()) <= 10 && strlen($this->getSortCode()) == 6;
    }

    public function getDisplayableSortCode()
    {
        return $this->displayableSortCode($this->getSortCode());
    }

    public function getDisplayableAccountNumber()
    {
        return $this->displayableAccountNumber($this->getAccountNumber());
    }

    public function __toString()
    {
        return sprintf(
            "%s %s %s",
            $this->getAccountName(),
            $this->getDisplayableSortCode(),
            $this->getDisplayableAccountNumber()
        );
    }
}
