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
    const STATUS_PENDING_WAIT_CLAIM = 'pending-wait-claim';
    const STATUS_PAID = 'paid';
    const STATUS_CLAIMED = 'claimed';
    const STATUS_MISSING = 'missing';
    const STATUS_FAILED = 'failed';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $createdDate;

    /**
     * @Assert\NotNull(message="Cashback status is required")
     * @Assert\Choice({"pending-claimable", "pending-wait-claim", "pending-payment", "paid",
     *                 "claimed", "missing", "failed"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * @Assert\NotNull(message="Policy is required")
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $policy;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $amount;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $initialAmount;

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

    public function getDate()
    {
        return $this->date;
    }

    public function setDate($date)
    {
        $this->date = $date;
    }

    /**
     * @return Policy
     */
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
        if (!$this->initialAmount && $this->greaterThanZero($amount)) {
            $this->initialAmount = $amount;
        }
    }

    public function getInitialAmount()
    {
        return $this->initialAmount;
    }

    public function getDisplayableAmount()
    {
        // amount is not set whilst pending claimable so use pot
        if (in_array($this->getStatus(), [self::STATUS_PENDING_CLAIMABLE, self::STATUS_PENDING_WAIT_CLAIM])) {
            return $this->getPolicy()->getPotValue();
        } elseif (in_array($this->getStatus(), [self::STATUS_PENDING_PAYMENT, self::STATUS_PAID])) {
            return $this->getAmount();
        } elseif (in_array($this->getStatus(), [self::STATUS_FAILED, self::STATUS_MISSING])) {
            return $this->getAmount();
        } elseif (in_array($this->getStatus(), [self::STATUS_CLAIMED])) {
            return 0;
        }
    }

    public function getDisplayableStatus()
    {
        if ($this->getStatus() == self::STATUS_PENDING_CLAIMABLE) {
            return 'Processing';
        } elseif ($this->getStatus() == self::STATUS_PENDING_PAYMENT) {
            return 'Approved';
        } elseif ($this->getStatus() == self::STATUS_PENDING_WAIT_CLAIM) {
            return 'Waiting on claim';
        } elseif ($this->getStatus() == self::STATUS_PAID) {
            return 'Paid';
        } elseif ($this->getStatus() == self::STATUS_MISSING) {
            return 'Missing payment details';
        } elseif ($this->getStatus() == self::STATUS_FAILED) {
            return 'Invalid payment details';
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
        $this->sortCode = str_replace('-', '', $sortCode);
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

    public function isAmountReduced()
    {
        return $this->greaterThanZero($this->initialAmount - $this->amount);
    }

    public static function sumCashback($cashbacks)
    {
        $total = 0;
        foreach ($cashbacks as $cashback) {
            $total += $cashback->getAmount();
        }

        return $total;
    }

    public function getExpectedStatus()
    {
        if (in_array($this->getStatus(), [self::STATUS_CLAIMED, self::STATUS_PAID])) {
            throw new \Exception('Unable to suggest status for paid/claimed cashback');
        }

        // we assume a basic validation on the object beforehand, however do very basic checks
        // for missing data
        if (!$this->getAccountName() || !$this->getAccountNumber() || !$this->getSortCode()
            || mb_strlen($this->getAccountName()) == 0 || mb_strlen($this->getAccountNumber()) == 0 ||
            mb_strlen($this->getSortCode()) == 0) {
            // keep the failed status
            if ($this->getStatus() == self::STATUS_FAILED) {
                return self::STATUS_FAILED;
            }

            return self::STATUS_MISSING;
        }

        if ($this->getPolicy()->getStatus() == Policy::STATUS_EXPIRED) {
            return self::STATUS_PENDING_PAYMENT;
        } elseif ($this->getPolicy()->getStatus() == Policy::STATUS_EXPIRED_CLAIMABLE) {
            return self::STATUS_PENDING_CLAIMABLE;
        } elseif ($this->getPolicy()->getStatus() == Policy::STATUS_EXPIRED_WAIT_CLAIM) {
            return self::STATUS_PENDING_WAIT_CLAIM;
        }

        return self::STATUS_PENDING_CLAIMABLE;
    }
}
