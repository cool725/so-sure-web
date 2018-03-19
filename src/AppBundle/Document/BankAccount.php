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
    use DateTrait;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @AppAssert\BankAccountName()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $accountName;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $bankName;

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
     * @AppAssert\Token()
     * @MongoDB\Field(type="string")
     * @Assert\Length(min="6", max="18")
     * @Gedmo\Versioned
     */
    protected $reference;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $mandate;

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

    public function setBankName($bankName)
    {
        $this->bankName = $bankName;
    }

    public function getBankName()
    {
        return $this->bankName;
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

    public function setBankAddress(Address $address = null)
    {
        $this->bankAddress = $address;
    }

    public function getBankAddress()
    {
        return $this->bankAddress;
    }

    public function isValid($transformed = true)
    {
        return $this->validateSortCode($this->getSortCode())
            && $this->validateAccountNumber($this->getAccountNumber(), $transformed);
    }

    public function getDisplayableSortCode()
    {
        return $this->displayableSortCode($this->getSortCode());
    }

    public function getDisplayableAccountNumber()
    {
        return $this->displayableAccountNumber($this->getAccountNumber());
    }

    public function setReference($reference)
    {
        $this->reference = $reference;
    }

    public function getReference()
    {
        return $this->reference;
    }

    public function generateReference(User $user, $sequence)
    {
        $reference = sprintf('%s5%010d', strtoupper(substr($user->getLastName(), 0, 1)), $sequence);
        $this->setReference($reference);

        return $reference;
    }

    /**
     * @return \DateTime
     */
    public function getMandate()
    {
        return $this->mandate;
    }

    /**
     * @param \DateTime $mandate
     */
    public function setMandate(\DateTime $mandate)
    {
        $this->mandate = $mandate;
    }

    public function getPaymentDate(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $days = 4;
        if ($this->getMandate()) {
            $days = 3;
        }
        // TODO: Check timezone
        // 3pm cutoff or will date place the following day
        if ($this->isWeekDay($date) && $date->format('H') >= 15) {
            $days++;
        }

        return $this->addBusinessDays($date, $days);
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
