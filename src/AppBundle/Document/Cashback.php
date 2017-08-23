<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\CashbackRepository")
 * @Gedmo\Loggable
 */
class Cashback
{
    use CurrencyTrait;

    const STATUS_PENDING_CLAIMABLE = 'pending-claimable';
    const STATUS_PENDING_PAYMENT = 'pending-payment';
    const STATUS_PAID = 'paid';
    const STATUS_CLAIMED = 'claimed';
    const STATUS_FAILED = 'failed';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $createdDate;

    /**
     * @Assert\NotNull(message="Cashback status is required")
     * @Assert\Choice({"pending-claimable", "pending-payment", "paid", "claimed", "failed"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $paidDate;

    /**
     * @Assert\NotNull(message="Policy is required")
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     */
    protected $policy;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $amount;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $reference;

    /**
     * @AppAssert\SortCode()
     * @MongoDB\Field(type="string")
     * @Assert\Length(min="6", max="6")
     * @Gedmo\Versioned
     */
    protected $sortCode;

    /**
     * @AppAssert\BankAccountNumber()
     * @MongoDB\Field(type="string")
     * @Assert\Length(min="8", max="8")
     * @Gedmo\Versioned
     */
    protected $accountNumber;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="2", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $accountName;

    public function __construct()
    {
        $this->createdDate = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCreatedDate()
    {
        return $this->createdDate;
    }

    public function setCreatedDate($createdDate)
    {
        $this->createdDate = $createdDate;
    }

    public function getPaidDate()
    {
        return $this->paidDate;
    }

    public function setPaidDate($paidDate)
    {
        $this->paidDate = $paidDate;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }
    
    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;
    }

    public function getDisplayableAmount()
    {
        // amount is not set whilst pending claimable so use pot
        if ($this->getStatus() == self::STATUS_PENDING_CLAIMABLE) {
            return $this->getPolicy()->getPotValue();
        } elseif (in_array($this->getStatus(), [self::STATUS_PENDING_PAYMENT, self::STATUS_PAID])) {
            return $this->getAmount();
        } elseif (in_array($this->getStatus(), [self::STATUS_FAILED, self::STATUS_CLAIMED])) {
            return 0;
        }
    }

    public function getDisplayableStatus()
    {
        if ($this->getStatus() == self::STATUS_PENDING_CLAIMABLE) {
            return 'Processing';
        } elseif ($this->getStatus() == self::STATUS_PENDING_PAYMENT) {
            return 'Approved';
        } elseif ($this->getStatus() == self::STATUS_PAID) {
            return 'Paid';
        } elseif ($this->getStatus() == self::STATUS_FAILED) {
            return 'Invalid or missing payment details';
        } elseif ($this->getStatus() == self::STATUS_CLAIMED) {
            return 'Declined due to claim';
        }
    }

    public function getReference()
    {
        return $this->reference;
    }

    public function setReference($reference)
    {
        $this->reference = $reference;
    }

    public function getSortCode()
    {
        return $this->sortCode;
    }

    public function setSortCode($sortCode)
    {
        $this->sortCode = $sortCode;
    }

    public function getAccountNumber()
    {
        return $this->accountNumber;
    }

    public function setAccountNumber($accountNumber)
    {
        $this->accountNumber = $accountNumber;
    }

    public function getAccountName()
    {
        return $this->accountName;
    }

    public function setAccountName($accountName)
    {
        $this->accountName = $accountName;
    }

    public static function sumCashback($cashbacks)
    {
        $total = 0;
        foreach ($cashbacks as $cashback) {
            $total += $cashback->getAmount();
        }

        return $total;
    }
}
