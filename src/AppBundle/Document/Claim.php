<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ClaimRepository")
 * @Gedmo\Loggable
 */
class Claim
{
    use CurrencyTrait;

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
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $replacementImei;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $recordedDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $lossDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $notificationDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $replacementReceivedDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $createdDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $closedDate;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $number;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="5000")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $description;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $location;

    /**
     * @Assert\Choice({"loss", "theft", "damage", "warranty", "extended-warranty"})
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @Assert\Choice({"in-review", "approved", "settled", "declined", "withdrawn"})
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $daviesStatus;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $notes;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $suspectedFraud;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $shouldCancelPolicy;

    /**
     * @Assert\Type("bool")
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
    protected $unauthorizedCalls;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $accessories;

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
    protected $claimHandlingFees;

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

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $force;

    /**
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $crimeRef;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $validCrimeRef;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $shippingAddress;

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

        if (in_array($this->getType(), [self::TYPE_WARRANTY, self::TYPE_EXTENDED_WARRANTY]) &&
            in_array($status, [self::STATUS_APPROVED, self::STATUS_DECLINED])) {
            throw new \InvalidArgumentException(sprintf('Unable to use approved/declined with Warranty Types'));
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

    public function getShouldCancelPolicy()
    {
        return $this->shouldCancelPolicy;
    }

    public function setShouldCancelPolicy($shouldCancelPolicy)
    {
        $this->shouldCancelPolicy = $shouldCancelPolicy;
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

    public function getClaimHandlingFees()
    {
        return $this->claimHandlingFees;
    }

    public function setClaimHandlingFees($claimHandlingFees)
    {
        $this->claimHandlingFees = $claimHandlingFees;
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

    public function getForce()
    {
        return $this->force;
    }

    public function setForce($force)
    {
        $this->force = $force;
    }

    public function getCrimeRef()
    {
        return $this->crimeRef;
    }

    public function setCrimeRef($crimeRef)
    {
        $this->crimeRef = $crimeRef;
    }

    public function isValidCrimeRef()
    {
        return $this->validCrimeRef;
    }

    public function setValidCrimeRef($validCrimeRef)
    {
        $this->validCrimeRef = $validCrimeRef;
    }

    public function getShippingAddress()
    {
        return $this->shippingAddress;
    }

    public function setShippingAddress($shippingAddress)
    {
        $this->shippingAddress = $shippingAddress;
    }

    public function isMonetaryClaim($includeOpen = false)
    {
        $statuses = [self::STATUS_SETTLED];
        if ($includeOpen) {
            $statuses[] = self::STATUS_APPROVED;
        }

        return in_array($this->getStatus(), $statuses);
    }

    public function isOwnershipTransferClaim()
    {
        return in_array($this->getType(), [self::TYPE_LOSS, self::TYPE_THEFT]);
    }

    public static function sumClaims($claims)
    {
        $data = [
            'total' => 0,
            self::STATUS_INREVIEW => 0,
            self::STATUS_APPROVED => 0,
            self::STATUS_SETTLED => 0,
            self::STATUS_DECLINED => 0,
            self::STATUS_WITHDRAWN => 0,
        ];
        foreach ($claims as $claim) {
            $data[$claim->getStatus()]++;
            $data['total']++;
        }

        return $data;
    }

    public function toModalArray()
    {
        return [
            'number' => $this->getNumber(),
            'notes' => $this->getNotes(),
            'id' => $this->getId(),
            'policyId' => $this->getPolicy()->getId(),
            'policyNumber' => $this->getPolicy()->getPolicyNumber(),
            'handler' => $this->getHandler() ? $this->getHandler()->getName() : 'unknown',
            'replacementPhone' => $this->getReplacementPhone(),
            'replacementPhoneId' => $this->getReplacementPhone() ? $this->getReplacementPhone()->getId() : null,
            'replacementImei' => $this->getReplacementImei(),
            'recordedDate' => $this->getRecordedDate() ? $this->getRecordedDate()->format(\DateTime::ATOM) : null,
            'lossDate' => $this->getLossDate() ? $this->getLossDate()->format(\DateTime::ATOM) : null,
            'notificationDate' => $this->getNotificationDate() ?
                $this->getNotificationDate()->format(\DateTime::ATOM) :
                null,
            'replacementReceivedDate' => $this->getReplacementReceivedDate() ?
                $this->getReplacementReceivedDate()->format(\DateTime::ATOM) :
                null,
            'closedDate' => $this->getClosedDate() ? $this->getClosedDate()->format(\DateTime::ATOM) : null,
            'description' => $this->getDescription(),
            'location' => $this->getLocation(),
            'type' => $this->getType(),
            'status' => $this->getStatus(),
            'daviesStatus' => $this->getDaviesStatus(),
            'notes' => $this->getNotes(),
            'suspectedFraud' => $this->getSuspectedFraud(),
            'shouldCancelPolicy' => $this->getShouldCancelPolicy(),
            'processed' => $this->getProcessed(),
            'excess' => $this->toTwoDp($this->getExcess()),
            'unauthorizedCalls' => $this->toTwoDp($this->getUnauthorizedCalls()),
            'accessories' => $this->toTwoDp($this->getAccessories()),
            'phoneReplacementCost' => $this->toTwoDp($this->getPhoneReplacementCost()),
            'transactionFees' => $this->toTwoDp($this->getTransactionFees()),
            'claimHandlingFees' => $this->toTwoDp($this->getClaimHandlingFees()),
            'reservedValue' => $this->toTwoDp($this->getReservedValue()),
            'incurred' => $this->toTwoDp($this->getIncurred()),
            'force' => $this->getForce(),
            'crimeRef' => $this->getCrimeRef(),
            'validCrimeRef' => $this->isValidCrimeRef(),
            'shippingAddress' => $this->getShippingAddress(),
        ];
    }
}
