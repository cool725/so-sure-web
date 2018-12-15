<?php
namespace AppBundle\Classes;

use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\PhoneTrait;

abstract class HandlerClaim
{
    use PhoneTrait;
    use CurrencyTrait;

    const TYPE_LOSS = 'Loss';
    const TYPE_THEFT = 'Theft';
    const TYPE_DAMAGE = 'Damage';
    const TYPE_WARRANTY = 'Warranty';
    const TYPE_EXTENDED_WARRANTY = 'Extended Warranty';

    public $client;
    public $claimNumber;
    public $insuredName;
    public $riskPostCode;
    public $shippingAddress;
    /** @var \DateTime */
    public $lossDate;
    /** @var \DateTime */
    public $startDate;
    /** @var \DateTime */
    public $endDate;

    // losss, theft, damage??
    public $lossType;
    public $lossDescription;
    public $location;

    // Open, Closed, ReOpen, ReClosed
    public $status;

    // settled, repudiated (declined), and withdrawn
    public $miStatus;

    public $replacementMake;
    public $replacementModel;
    public $replacementImei;
    /** @var \DateTime */
    public $replacementReceivedDate;

    public $phoneReplacementCost;
    public $phoneReplacementCostReserve;
    public $accessories;
    public $accessoriesReserve;
    public $unauthorizedCalls;
    public $unauthorizedCallsReserve;
    public $feesReserve;

    public $reserved;
    public $incurred;
    public $handlingFees;
    // will appear regardless of if paid/unpaid
    public $excess;

    public $policyNumber;
    public $notificationDate;
    public $dateCreated;
    public $dateClosed;

    public $totalIncurred;

    public $risk;

    public $daysSinceInception;
    public $initialSuspicion;
    public $finalSuspicion;

    public $unobtainableFields;

    public $isReplacementRepair = null;

    public function __construct()
    {
        $this->unobtainableFields = [];
    }

    public function getIncurred()
    {
        if (!$this->incurred) {
            return 0;
        }

        return $this->incurred;
    }

    public function getReserved()
    {
        if (!$this->reserved) {
            return 0;
        }

        return $this->reserved;
    }

    public function getExpectedExcessValue(Claim $claim, $repairDiscount = false)
    {
        $excess = $claim->getExpectedExcessValue();
        if ($repairDiscount) {
            $excess -= 25;
        }

        return $excess;
    }

    public function isExcessValueCorrect(Claim $claim, $negativeExcessAllowed = false)
    {
        $excessNoRepairDiscount = $this->getExpectedExcessValue($claim);
        $excessRepairDiscount = $this->getExpectedExcessValue($claim, true);

        if ($this->excess > 0) {
            return $this->areEqualToTwoDp($this->excess, $excessNoRepairDiscount) ||
                $this->areEqualToTwoDp($this->excess, $excessRepairDiscount);
        } elseif ($this->excess < 0) {
            if ($negativeExcessAllowed) {
                return $this->areEqualToTwoDp(abs($this->excess), $excessNoRepairDiscount) ||
                    $this->areEqualToTwoDp(abs($this->excess), $excessRepairDiscount);
            } else {
                return false;
            }
        }

        // Settled claims should always have excess
        if ($this->getClaimStatus() == Claim::STATUS_SETTLED) {
            return false;
        }

        return true;
    }

    public function isIncurredValueCorrect()
    {
        $expected = $this->getExpectedIncurred();
        if ($expected === null) {
            return null;
        }

        return $this->areEqualToTwoDp($this->getIncurred(), $this->getExpectedIncurred());
    }

    public function isPhoneReplacementCostCorrect()
    {
        if ($this->replacementImei || $this->replacementReceivedDate) {
            if ($this->phoneReplacementCost <= 0) {
                return false;
            }
        }

        return true;
    }

    public function isClaimWarranty()
    {
        return in_array($this->getClaimType(), [HandlerClaim::TYPE_WARRANTY]);
    }

    public function isClaimWarrantyOrExtended()
    {
        return in_array($this->getClaimType(), [HandlerClaim::TYPE_WARRANTY, HandlerClaim::TYPE_EXTENDED_WARRANTY]);
    }


    public function getPolicyNumber()
    {
        if (preg_match('/[^a-zA-Z]*([a-zA-Z]+\/[0-9]{4,4}\/[0-9]{5,20}).*/', $this->policyNumber, $matches) &&
            isset($matches[1])) {
            return $matches[1];
        }

        return null;
    }

    protected function nullIfBlank($field, $fieldName = null, $ref = null)
    {
        if (!$field || $this->isNullableValue($field)) {
            return null;
        } elseif ($this->isUnobtainableValue($field)) {
            if ($fieldName && $ref) {
                $ref->unobtainableFields[] = $fieldName;
            }
            return null;
        }
        return str_replace('_x000D_', PHP_EOL, str_replace('£', '', trim($field)));
    }

    /**
     * Reads an IMEI value, and if it has a junk value returns null
     * @param object $field is the variable to be read
     * @return object the field if it is an IMEI, or null if it is junk
     */
    protected function nullImeiIfBlank($field)
    {
        $imei = $this->nullIfBlank($field, 'replacementImei', $this);
        if ($imei) {
            $imei = $this->normalizeImei($imei);
        }
        return $imei;
    }

    /**
     * Determines if a given value corresponds to what the claim handler gives when data has not yet
     * been provided but may be in the future.
     * @param string $value is the given value
     * @return boolean true iff the value corresponds to absent data
     */
    abstract public function isNullableValue($value);

    /**
     * Determines if a given value corresponds to what the claim handler gives when data is not available.
     * @param string $value is the given value
     * @return boolean true iff the value corresponds to unavailable data.
     */
    abstract public function isUnobtainableValue($value);
    abstract public function hasError();
    abstract public function getClaimType();
    abstract public function getReplacementPhoneDetails();
    abstract public function isOpen($includeReOpened = false);
    abstract public function isClosed($includeReClosed = false);
    abstract public function getClaimStatus();
    abstract public function isApproved();
    abstract public function getExpectedIncurred();
    abstract public function fromArray($data, $columns);
    abstract public function isReplacementRepaired();
    abstract public static function create($data, $columns);
}
