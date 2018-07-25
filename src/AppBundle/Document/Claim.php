<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Validator\Constraints\AlphanumericSpaceDotValidator;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ClaimRepository")
 * @Gedmo\Loggable
 */
class Claim
{
    use CurrencyTrait;
    use ImeiTrait;

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
    // Temporary status to allow the system to suggest closing a claim, as the policy is about to be cancelled
    const STATUS_PENDING_CLOSED = 'pending-closed';

    const WARNING_FLAG_DAVIES_NAME_MATCH = 'davies-name-match';
    const WARNING_FLAG_DAVIES_POSTCODE = 'davies-postcode';
    const WARNING_FLAG_BRIGHTSTAR_NAME_MATCH = 'brightstar-name-match';
    const WARNING_FLAG_BRIGHTSTAR_POSTCODE = 'brighstar-postcode';
    const WARNING_FLAG_DAVIES_REPLACEMENT_COST_HIGHER = 'davies-replacement-cost-higher';
    const WARNING_FLAG_DAVIES_INCORRECT_EXCESS = 'davies-incorrect-excess';
    const WARNING_FLAG_DAVIES_IMEI_MISMATCH = 'davies-imei-mismatch';

    // technically not a warning flag, but fits nicely under that for UI with little change required
    // and very little usage envisioned
    const WARNING_FLAG_IGNORE_USER_DECLINED = 'ignore-user-declined';
    const WARNING_FLAG_IGNORE_POLICY_EXPIRE_CLAIM_WAIT = 'ignore-policy-expire-claim-wait';

    public static $warningFlags = [
        self::WARNING_FLAG_DAVIES_NAME_MATCH => self::WARNING_FLAG_DAVIES_NAME_MATCH,
        self::WARNING_FLAG_DAVIES_POSTCODE => self::WARNING_FLAG_DAVIES_POSTCODE,
        self::WARNING_FLAG_BRIGHTSTAR_NAME_MATCH => self::WARNING_FLAG_BRIGHTSTAR_NAME_MATCH,
        self::WARNING_FLAG_BRIGHTSTAR_POSTCODE => self::WARNING_FLAG_BRIGHTSTAR_POSTCODE,
        self::WARNING_FLAG_IGNORE_USER_DECLINED => self::WARNING_FLAG_IGNORE_USER_DECLINED,
        self::WARNING_FLAG_IGNORE_POLICY_EXPIRE_CLAIM_WAIT => self::WARNING_FLAG_IGNORE_POLICY_EXPIRE_CLAIM_WAIT,
        self::WARNING_FLAG_DAVIES_REPLACEMENT_COST_HIGHER => self::WARNING_FLAG_DAVIES_REPLACEMENT_COST_HIGHER,
        self::WARNING_FLAG_DAVIES_INCORRECT_EXCESS => self::WARNING_FLAG_DAVIES_INCORRECT_EXCESS,
        self::WARNING_FLAG_DAVIES_IMEI_MISMATCH => self::WARNING_FLAG_DAVIES_IMEI_MISMATCH,
    ];

    public static $claimTypes = [
        self::TYPE_DAMAGE,
        self::TYPE_THEFT,
        self::TYPE_EXTENDED_WARRANTY,
        self::TYPE_WARRANTY,
        self::TYPE_LOSS
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="claims")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $policy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="linkedClaims")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $linkedPolicy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     */
    public $handler;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @Gedmo\Versioned
     * @var Phone
     */
    public $replacementPhone;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $replacementPhoneDetails;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $replacementImei;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $recordedDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $lossDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $notificationDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $replacementReceivedDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $createdDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $approvedDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
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
     * @Assert\Choice({"loss", "theft", "damage", "warranty", "extended-warranty"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @Assert\Choice({"in-review", "approved", "settled", "declined", "withdrawn", "pending-closed"}, strict=true)
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
     * @Assert\Length(min="1", max="500")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $notes;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $initialSuspicion;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $finalSuspicion;

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
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $totalIncurred;

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

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $fnolRisk;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $fnolRiskReason;

    /**
     * @MongoDB\ReferenceMany(targetDocument="Charge", mappedBy="claim", cascade={"persist"})
     */
    protected $charges = array();

    /**
     * @MongoDB\Field(type="collection")
     */
    protected $ignoreWarningFlags = array();

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

    /**
     * @return Policy
     */
    public function getPolicy()
    {
        return $this->policy;
    }

    /**
     * @return PhonePolicy|null
     */
    public function getPhonePolicy()
    {
        if ($this->policy instanceof PhonePolicy) {
            return $this->policy;
        }

        return null;
    }

    public function setPolicy($policy)
    {
        if (!$this->getFnolRisk()) {
            $this->setFnolRisk($policy->getRisk());
        }
        if (!$this->getFnolRiskReason()) {
            $this->setFnolRiskReason($policy->getRiskReason());
        }
        $this->policy = $policy;
    }

    /**
     * @return Policy
     */
    public function getLinkedPolicy()
    {
        return $this->linkedPolicy;
    }

    public function setLinkedPolicy($linkedPolicy)
    {
        $this->linkedPolicy = $linkedPolicy;
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

    public function setType($type, $forceChange = false)
    {
        if ($this->type && $this->type != $type && !$forceChange) {
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
        
        if (!in_array($status, [
            self::STATUS_APPROVED,
            self::STATUS_DECLINED,
            self::STATUS_INREVIEW,
            self::STATUS_PENDING_CLOSED,
            self::STATUS_SETTLED,
            self::STATUS_WITHDRAWN,
        ])) {
            throw new \Exception(sprintf('Status must be a valid status, not %s', $status));
        }

        // TODO: Was STATUS_DECLINED as well, but claim 77557421061 is declined - mistake in data?
        if (in_array($this->getType(), [self::TYPE_WARRANTY]) &&
            in_array($status, [self::STATUS_APPROVED])) {
            throw new \InvalidArgumentException(sprintf(
                'Unable to use approved with Warranty Types. %s/%s',
                $this->getNumber(),
                $this->getId()
            ));
        }

        // Don't trust davies enough with data - changing from approved / settled to declined / withdrawn
        // can have financial and pot reward implications and needs to be checked
        if (in_array($this->status, [self::STATUS_APPROVED, self::STATUS_SETTLED]) &&
            in_array($status, [self::STATUS_DECLINED, self::STATUS_WITHDRAWN])) {
            // @codingStandardsIgnoreStart
            throw new \InvalidArgumentException(sprintf(
                'Unable to change from approved/settled status to declined/withdrawn automatically. Review implication and manually update %s/%s',
                $this->getNumber(),
                $this->getId()
            ));
            // @codingStandardsIgnoreEnd
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

    public function getReplacementPhoneDetails()
    {
        return $this->replacementPhoneDetails;
    }

    public function setReplacementPhoneDetails($replacementPhoneDetails)
    {
        $validator = new AlphanumericSpaceDotValidator();

        $this->replacementPhoneDetails = $validator->conform(mb_substr($replacementPhoneDetails, 0, 100));
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

    public function setNumber($number, $allowChange = false)
    {
        if ($this->number && $this->number != (string) $number && !$allowChange) {
            throw new \Exception('Unable to change claim number');
        }

        $this->number = (string) $number;
    }

    public function getSuspectedFraud()
    {
        // if finalSuspicion is null return initialSuspicion
        return ($this->finalSuspicion == null) ? $this->initialSuspicion : $this->finalSuspicion;
    }

    public function getInitialSuspicion()
    {
        return $this->initialSuspicion;
    }

    public function setInitialSuspicion($initialSuspicion)
    {
        $this->initialSuspicion = $initialSuspicion;
    }

    public function getFinalSuspicion()
    {
        return $this->finalSuspicion;
    }

    public function setFinalSuspicion($finalSuspicion)
    {
        $this->finalSuspicion = $finalSuspicion;
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
        $validator = new AlphanumericSpaceDotValidator();

        $this->notes = $validator->conform(mb_substr($notes, 0, 500));
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

    /**
     * @return \DateTime
     */
    public function getLossDate()
    {
        return $this->lossDate;
    }

    public function setLossDate($lossDate)
    {
        $this->lossDate = $lossDate;
    }

    /**
     * @return \DateTime
     */
    public function getNotificationDate()
    {
        return $this->notificationDate;
    }

    public function setNotificationDate($notificationDate)
    {
        $this->notificationDate = $notificationDate;
    }

    /**
     * @return \DateTime
     */
    public function getCreatedDate()
    {
        return $this->createdDate;
    }

    public function setCreatedDate($createdDate)
    {
        $this->createdDate = $createdDate;
    }

    /**
     * @return \DateTime
     */
    public function getApprovedDate()
    {
        return $this->approvedDate;
    }

    public function setApprovedDate($approvedDate)
    {
        $this->approvedDate = $approvedDate;
    }

    /**
     * @return \DateTime
     */
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

    public function isWithin30DaysOfPolicyInception()
    {
        if (!$this->getRecordedDate() || !$this->getPolicy() || !$this->getPolicy()->getStart()) {
            return false;
        }

        $diff = $this->getRecordedDate()->diff($this->getPolicy()->getStart());
        return $diff->days < 30;
    }

    public function isPhoneReturnExpected()
    {
        if (in_array($this->getType(), [Claim::TYPE_DAMAGE, Claim::TYPE_WARRANTY, Claim::TYPE_EXTENDED_WARRANTY])) {
            return true;
        } elseif (in_array($this->getType(), [Claim::TYPE_LOSS, Claim::TYPE_THEFT])) {
            return false;
        } else {
            throw new \Exception(sprintf('Unknown type for phone returned: %s', $this->getType()));
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

    public function getTotalIncurred()
    {
        return $this->totalIncurred;
    }

    public function setTotalIncurred($totalIncurred)
    {
        $this->totalIncurred = $totalIncurred;
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

    public function getFnolRisk()
    {
        return $this->fnolRisk;
    }

    public function setFnolRisk($fnolRisk)
    {
        $this->fnolRisk = $fnolRisk;
    }

    public function getFnolRiskReason()
    {
        return $this->fnolRiskReason;
    }

    public function setFnolRiskReason($fnolRiskReason)
    {
        $this->fnolRiskReason = $fnolRiskReason;
    }

    public function getCharges()
    {
        return $this->charges;
    }

    public function getLastChargeAmount()
    {
        $charge = $this->getLastCharge();
        if ($charge) {
            return $charge->getAmount();
        }

        return 0;
    }

    public function getLastChargeAmountWithVat()
    {
        $charge = $this->getLastCharge();
        if ($charge) {
            return $charge->getAmountWithVat();
        }

        return 0;
    }

    public function getLastCharge()
    {
        $charges = $this->getCharges();
        if (!is_array($charges)) {
            $charges = $charges->getValues();
        }
        if (count($charges) == 0) {
            return null;
        }

        // sort more recent to older
        usort($charges, function ($a, $b) {
            return $a->getCreatedDate() < $b->getCreatedDate();
        });
        //\Doctrine\Common\Util\Debug::dump($payments, 3);

        return $charges[0];
    }

    public function totalCharges()
    {
        $total = 0;
        foreach ($this->getCharges() as $charge) {
            $total += $charge->getAmount();
        }

        return $total;
    }

    public function totalChargesWithVat()
    {
        $total = 0;
        foreach ($this->getCharges() as $charge) {
            $total += $charge->getAmountWithVat();
        }

        return $total;
    }

    public function addCharge(Charge $charge)
    {
        $charge->setClaim($this);
        $this->charges[] = $charge;
    }

    public function isMonetaryClaim($includeApproved = false)
    {
        $statuses = [self::STATUS_SETTLED];
        if ($includeApproved) {
            $statuses[] = self::STATUS_APPROVED;
        }

        return in_array($this->getStatus(), $statuses);
    }

    public function isLostTheft()
    {
        return in_array($this->getType(), [self::TYPE_LOSS, self::TYPE_THEFT]);
    }

    public function isLostTheftApproved()
    {
        // Including inreview to prevent possible multiple claims at the same time
        if ($this->isLostTheft() &&
            in_array($this->getStatus(), [self::STATUS_APPROVED, self::STATUS_SETTLED, self::STATUS_INREVIEW])) {
                return true;
        }

        return false;
    }

    public function isOwnershipTransferClaim()
    {
        return in_array($this->getType(), [self::TYPE_LOSS, self::TYPE_THEFT]);
    }

    public function getIgnoreWarningFlags()
    {
        $data = [];
        foreach (static::$warningFlags as $key => $value) {
            $data[$key] = $this->isIgnoreWarningFlagSet($key);
        }

        return $data;
    }

    public function setIgnoreWarningFlags($flag)
    {
        $this->ignoreWarningFlags[] = $flag;
    }

    public function isIgnoreWarningFlagSet($flag)
    {
        return in_array($flag, $this->ignoreWarningFlags);
    }

    public function clearIgnoreWarningFlags()
    {
        $this->ignoreWarningFlags = array();
    }

    public function hasIgnoreUserDeclined()
    {
        return  $this->isIgnoreWarningFlagSet(self::WARNING_FLAG_IGNORE_USER_DECLINED);
    }

    public static function sumClaims($claims)
    {
        $data = [
            'total' => 0,
            'approved-settled' => 0,
            self::STATUS_INREVIEW => 0,
            self::STATUS_APPROVED => 0,
            self::STATUS_SETTLED => 0,
            self::STATUS_DECLINED => 0,
            self::STATUS_WITHDRAWN => 0,
            self::STATUS_PENDING_CLOSED => 0,
        ];
        foreach ($claims as $claim) {
            $data[$claim->getStatus()]++;
            $data['total']++;
            if (in_array($claim->getStatus(), [self::STATUS_APPROVED, self::STATUS_SETTLED])) {
                $data['approved-settled']++;
            }
        }

        return $data;
    }

    public static function attributeClaims($claims, $group = true, $percent = false)
    {
        $total = 0;
        $data = [];
        foreach ($claims as $claim) {
            if ($claim->getPolicy()->getCompany()) {
                $source = 'Company';
            } elseif ($attribution = $claim->getPolicy()->getUser()->getAttribution()) {
                if ($group) {
                    $source = $attribution->getCampaignSourceGroup();
                } else {
                    $source = $attribution->getNormalizedCampaignSource();
                }
            } else {
                $source = Attribution::SOURCE_UNTRACKED;
            }

            if (isset($data[$source])) {
                $data[$source]++;
            } else {
                $data[$source] = 1;
            }
            $total++;
        }

        if ($percent) {
            if ($total == 0) {
                return [];
            }

            $percent = [];
            foreach ($data as $key => $value) {
                $percent[$key] = sprintf("%0.1f%%", (100 * $value / $total));
            }

            return $percent;
        }

        return $data;
    }

    public function toModalArray()
    {
        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $this->getPolicy();
        return [
            'number' => $this->getNumber(),
            'notes' => $this->getNotes(),
            'id' => $this->getId(),
            'policyPhone' => $phonePolicy->getPhone()->__toString(),
            'policyId' => $this->getPolicy()->getId(),
            'policyNumber' => $this->getPolicy()->getPolicyNumber(),
            'handler' => $this->getHandler() ? $this->getHandler()->getName() : 'unknown',
            'replacementPhone' => $this->getReplacementPhone(),
            'replacementPhoneDetails' => $this->getReplacementPhoneDetails(),
            'replacementPhoneId' => $this->getReplacementPhone() ? $this->getReplacementPhone()->getId() : null,
            'replacementImei' => $this->getReplacementImei(),
            'validReplacementImei' => $this->isImei($this->getReplacementImei()),
            'recordedDate' => $this->getRecordedDate() ? $this->getRecordedDate()->format(\DateTime::ATOM) : null,
            'approvedDate' => $this->getApprovedDate() ? $this->getApprovedDate()->format(\DateTime::ATOM) : null,
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
            'initialSuspicion' => $this->getInitialSuspicion(),
            'finalSuspicion' => $this->getFinalSuspicion(),
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

    public function getExpectedExcess()
    {
        if (in_array($this->getStatus(), [
            Claim::STATUS_DECLINED,
            Claim::STATUS_WITHDRAWN,
        ])) {
            return 0;
        }
        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $this->getPolicy();
        $picSureEnabled = $phonePolicy->isPicSurePolicy();
        $picSureValidated = $phonePolicy->isPicSureValidated();

        return self::getExcessValue($this->getType(), $picSureValidated, $picSureEnabled);
    }

    public static function getExcessValue($type, $picSureValidated, $picSureEnabled)
    {
        if ($picSureEnabled && !$picSureValidated) {
            return 150;
        }

        if (in_array($type, [Claim::TYPE_LOSS, Claim::TYPE_THEFT])) {
            return 70;
        } elseif (in_array($type, [
            Claim::TYPE_DAMAGE,
            Claim::TYPE_WARRANTY,
            Claim::TYPE_EXTENDED_WARRANTY
        ])) {
            return 50;
        }

        throw new \Exception(sprintf('Unknown claim type %s', $type));
    }
}
