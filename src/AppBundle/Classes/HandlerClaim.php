<?php
namespace AppBundle\Classes;

use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ImeiTrait;

abstract class HandlerClaim
{
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

    public $brightstarProductNumber;
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
    public $reciperoFee;
    public $transactionFees;
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

    public function getExpectedExcess($validated, $picSureEnabled)
    {
        try {
            return Claim::getExcessValue($this->getClaimType(), $validated, $picSureEnabled);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function isExcessValueCorrect($validated = true, $picSureEnabled = false, $negativeExcessAllowed = false)
    {
        if ($this->excess > 0) {
            return $this->areEqualToTwoDp($this->excess, $this->getExpectedExcess($validated, $picSureEnabled));
        } elseif ($this->excess < 0) {
            if ($negativeExcessAllowed) {
                return $this->areEqualToTwoDp(
                    abs($this->excess),
                    $this->getExpectedExcess($validated, $picSureEnabled)
                );
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

        return $this->areEqualToTwoDp($this->incurred, $this->getExpectedIncurred());
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

    public function getExpectedIncurred()
    {
        // Incurred fee only appears to be populated at the point where the phone replacement cost is known,
        if (!$this->phoneReplacementCost || $this->phoneReplacementCost < 0 ||
            $this->areEqualToTwoDp(0, $this->phoneReplacementCost)) {
            return null;
        }

        // phone replacement cost now excludes excess
        $total = $this->unauthorizedCalls + $this->accessories + $this->phoneReplacementCost +
            $this->transactionFees + $this->handlingFees + $this->reciperoFee;

        return $this->toTwoDp($total);
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

    abstract public function hasError();
    abstract public function getClaimType();
    abstract public function getReplacementPhoneDetails();
    abstract public function isOpen($includeReOpened = false);
    abstract public function isClosed($includeReClosed = false);
    abstract public function getClaimStatus();
    abstract public function isApproved();
    abstract public function fromArray($data, $columns);
    abstract public function isReplacementRepaired();
    abstract public static function create($data, $columns);
}
