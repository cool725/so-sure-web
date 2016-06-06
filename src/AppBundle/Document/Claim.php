<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ClaimRepository")
 * @Gedmo\Loggable
 */
class Claim
{
    const TYPE_LOSS = 'loss';
    const TYPE_THEFT = 'theft';
    const TYPE_DAMAGE = 'damage';
    const TYPE_WARRANTY = 'warranty';
    const TYPE_EXTENDED_WARRANTY = 'extended-warranty';

    const STATUS_INREVIEW = 'in-review';
    const STATUS_APPROVED = 'approved';
    const STATUS_SETTLED = 'settled';
    const STATUS_DECLINED = 'declined';
    const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     */
    protected $policy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     */
    public $handler;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @Gedmo\Versioned
     */
    public $replacementPhone;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $replacementImei;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $recordedDate;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $lossDate;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $notificationDate;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $replacementReceivedDate;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $createdDate;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $closedDate;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $number;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $description;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $location;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $daviesStatus;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $notes;

    /**
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $suspectedFraud;

    /**
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $processed;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $excess;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $unauthorizedCalls = 0;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $accessories = 0;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $phoneReplacementCost;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $transactionFees;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $reservedValue;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $incurred;

    public function __construct()
    {
        $this->recordedDate = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function getRecordedDate()
    {
        return $this->recordedDate;
    }

    public function setRecordedDate($recordedDate)
    {
        $this->recordedDate = $recordedDate;
    }

    public function isWithin30Days($date)
    {
        $claimsDate = $this->getClosedDate();
        if (!$claimsDate) {
            $claimsDate = $this->getRecordedDate();
        }
        return $claimsDate->diff($date)->days < 30;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    public function getHandler()
    {
        return $this->handler;
    }

    public function setHandler($handler)
    {
        $this->handler = $handler;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type)
    {
        if ($this->type && $this->type != $type) {
            throw new \Exception('Unable to change claim type');
        } elseif (!$type) {
            throw new \Exception('Type must be defined');
        }

        $this->type = $type;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        if (!$status) {
            throw new \Exception('Status must be defined');
        }

        $this->status = $status;
    }

    public function isOpen()
    {
        return in_array($this->getStatus(), [Claim::STATUS_APPROVED, Claim::STATUS_INREVIEW]);
    }

    public function getDaviesStatus()
    {
        return $this->daviesStatus;
    }

    public function setDaviesStatus($daviesStatus)
    {
        if (!$daviesStatus) {
            throw new \Exception('Status must be defined');
        }

        $this->daviesStatus = $daviesStatus;
    }

    public function getReplacementPhone()
    {
        return $this->replacementPhone;
    }

    public function setReplacementPhone($replacementPhone)
    {
        $this->replacementPhone = $replacementPhone;
    }

    public function getReplacementImei()
    {
        return $this->replacementImei;
    }

    public function setReplacementImei($replacementImei)
    {
        $this->replacementImei = $replacementImei;
    }

    public function getReplacementReceivedDate()
    {
        return $this->replacementReceivedDate;
    }

    public function setReplacementReceivedDate($date)
    {
        $this->replacementReceivedDate = $date;
    }

    public function getNumber()
    {
        return $this->number;
    }

    public function setNumber($number)
    {
        if ($this->number && $this->number != $number) {
            throw new \Exception('Unable to change claim number');
        }

        $this->number = $number;
    }

    public function getSuspectedFraud()
    {
        return $this->suspectedFraud;
    }

    public function setSuspectedFraud($suspectedFraud)
    {
        $this->suspectedFraud = $suspectedFraud;
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function setNotes($notes)
    {
        $this->notes = $notes;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function getLocation()
    {
        return $this->location;
    }

    public function setLocation($location)
    {
        $this->location = $location;
    }

    public function getLossDate()
    {
        return $this->lossDate;
    }

    public function setLossDate($lossDate)
    {
        $this->lossDate = $lossDate;
    }

    public function getNotificationDate()
    {
        return $this->notificationDate;
    }

    public function setNotificationDate($notificationDate)
    {
        $this->notificationDate = $notificationDate;
    }

    public function getCreatedDate()
    {
        return $this->createdDate;
    }

    public function setCreatedDate($createdDate)
    {
        $this->createdDate = $createdDate;
    }

    public function getClosedDate()
    {
        return $this->closedDate;
    }

    public function setClosedDate($closedDate)
    {
        $this->closedDate = $closedDate;
    }

    public function getExcess()
    {
        return $this->excess;
    }

    public function setExcess($excess)
    {
        $this->excess = $excess;
    }

    public function getExpectedExcess()
    {
        if ($this->getType() == Claim::TYPE_DAMAGE) {
            return 50;
        } elseif (in_array($this->getType(), [Claim::TYPE_LOSS, Claim::TYPE_THEFT])) {
            return 70;
        } else {
            throw new \Exception(sprintf('Unknown type for expected excess: %s', $this->getType()));
        }
    }

    public function getReservedValue()
    {
        return $this->reservedValue;
    }

    public function setReservedValue($reservedValue)
    {
        $this->reservedValue = $reservedValue;
    }

    public function getIncurred()
    {
        return $this->incurred;
    }

    public function setIncurred($incurred)
    {
        $this->incurred = $incurred;
    }

    public function getUnauthorizedCalls()
    {
        return $this->unauthorizedCalls;
    }

    public function setUnauthorizedCalls($unauthorizedCalls)
    {
        $this->unauthorizedCalls = $unauthorizedCalls;
    }

    public function getAccessories()
    {
        return $this->accessories;
    }

    public function setAccessories($accessories)
    {
        $this->accessories = $accessories;
    }

    public function getPhoneReplacementCost()
    {
        return $this->phoneReplacementCost;
    }

    public function setPhoneReplacementCost($phoneReplacementCost)
    {
        $this->phoneReplacementCost = $phoneReplacementCost;
    }

    public function getTransactionFees()
    {
        return $this->transactionFees;
    }

    public function setTransactionFees($transactionFees)
    {
        $this->transactionFees = $transactionFees;
    }

    public function getProcessed()
    {
        return $this->processed;
    }

    public function setProcessed($processed)
    {
        $this->processed = $processed;
    }

    public function isMonetaryClaim()
    {
        return in_array($this->getStatus(), [self::STATUS_SETTLED]);
    }

    public function isOwnershipTransferClaim()
    {
        return in_array($this->getType(), [self::TYPE_LOSS, self::TYPE_THEFT]);
    }

    public function getClaimHandlingFees()
    {
        if ($this->isOpen()) {
            return 0;
        }

        if ($this->getStatus() == self::STATUS_WITHDRAWN || $this->getStatus() == self::STATUS_DECLINED) {
            return $this->getIncurred();
        } else {
            return $this->getIncurred() - $this->getUnauthorizedCalls() - $this->getAccessories() -
                $this->getPhoneReplacementCost() - $this->getExcess();
        }
    }
}
