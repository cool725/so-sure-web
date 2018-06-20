<?php
namespace AppBundle\Classes;

use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ImeiTrait;

class DaviesClaim extends DaviesExcel
{
    use CurrencyTrait;
    use DateTrait;
    use ImeiTrait;

    const SHEET_NAME_V8 = 'Created - Cumulative';
    const SHEET_NAME_V7 = 'Created - Cumulative';
    const SHEET_NAME_V6 = 'Created - Cumulative';
    const SHEET_NAME_V1 = 'Original';
    const CLIENT_NAME = "So-Sure -Mobile";
    const COLUMN_COUNT_V1 = 31;
    const COLUMN_COUNT_V6 = 36;
    const COLUMN_COUNT_V7 = 37;
    const COLUMN_COUNT_V8 = 40;

    const STATUS_OPEN = 'Open';
    const STATUS_CLOSED = 'Closed';
    const STATUS_REOPENED = 'Re-Opened';
    const STATUS_REOPENED_ALT = 'ReOpened';
    const STATUS_RECLOSED = 'Re-Closed';
    const STATUS_RECLOSED_ALT = 'ReClosed';

    const MISTATUS_SETTLED = 'Settled';
    const MISTATUS_WITHDRAWN = 'Withdrawn';
    const MISTATUS_REPUDIATED = 'Repudiated';

    const MISTATUS_ADJUSTER_CORREPONDENCE = "Adjuster Correspondence";
    const MISTATUS_ADJUSTER_FEE = "Adjuster Fee";
    const MISTATUS_CLAIMANT_CORRESPONDENCE = "Claimant Correspondence";
    const MISTATUS_CONTRIBUTION = "Contribution";
    const MISTATUS_RECOVERY = "Recovery";
    const MISTATUS_RETURNED_CHEQUE = "Returned Cheque";
    const MISTATUS_SUPPLIER_CORRESPONDENCE = "Supplier Correspondence";
    const MISTATUS_SUPPLIER_FEE = "Supplier Fee";
    const MISTATUS_ERROR = "Error";
    const MISTATUS_DMS_ERROR = "DMS Error";
    const MISTATUS_COMPLAINT = "Complaint";
    const MISTATUS_INSURER = "Contact from insurer";

    const TYPE_LOSS = 'Loss';
    const TYPE_THEFT = 'Theft';
    const TYPE_DAMAGE = 'Damage';
    const TYPE_WARRANTY = 'Warranty';
    const TYPE_EXTENDED_WARRANTY = 'Extended Warranty';

    public static $breakdownEmailAddresses = [
        'laura.harvey@davies-group.com',
        'patrick@so-sure.com',
        'dylan@so-sure.com',
    ];

    public static $invoiceEmailAddresses = [
        'accounts.payable@davies-group.com',
        'laura.harvey@davies-group.com',
        'patrick@so-sure.com',
        'dylan@so-sure.com',
    ];

    public static $errorEmailAddresses = [
        'laura.harvey@davies-group.com',
        'Samantha.adams@davies-group.com',
        'Simon.Harvey@davies-group.com',
        'patrick@so-sure.com',
        'dylan@so-sure.com',
    ];

    public static $sheetNames = [
        self::SHEET_NAME_V8,
        self::SHEET_NAME_V1
    ];

    public static function getColumnsFromSheetName($sheetName)
    {
        if ($sheetName == self::SHEET_NAME_V8) {
            return self::COLUMN_COUNT_V8;
        } elseif ($sheetName == self::SHEET_NAME_V1) {
            return self::COLUMN_COUNT_V1;
        } else {
            throw new \Exception(sprintf('Unknown sheet name %s', $sheetName));
        }
    }

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

    public function hasError()
    {
        return in_array(mb_strtolower($this->miStatus), [
            mb_strtolower(self::MISTATUS_ERROR),
            mb_strtolower(self::MISTATUS_DMS_ERROR),
        ]);
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

    public function getReplacementPhoneDetails()
    {
        if ($this->replacementMake && $this->replacementModel) {
            return (sprintf(
                '%s %s',
                $this->replacementMake,
                $this->replacementModel
            ));
        } elseif ($this->replacementMake) {
            return $this->replacementMake;
        } elseif ($this->replacementModel) {
            return $this->replacementModel;
        } else {
            return null;
        }
    }

    public function isClaimWarranty()
    {
        return in_array($this->getClaimType(), [Claim::TYPE_WARRANTY]);
    }

    public function isClaimWarrantyOrExtended()
    {
        return in_array($this->getClaimType(), [Claim::TYPE_WARRANTY, Claim::TYPE_EXTENDED_WARRANTY]);
    }

    public function getClaimType()
    {
        $lossType = mb_strtolower($this->lossType);
        if (mb_stripos($lossType, mb_strtolower(self::TYPE_LOSS)) !== false) {
            return Claim::TYPE_LOSS;
        } elseif (mb_stripos($lossType, mb_strtolower(self::TYPE_THEFT)) !== false) {
            return Claim::TYPE_THEFT;
        } elseif (mb_stripos($lossType, mb_strtolower(self::TYPE_DAMAGE)) !== false) {
            return Claim::TYPE_DAMAGE;
        } elseif (mb_stripos($lossType, mb_strtolower(self::TYPE_EXTENDED_WARRANTY)) !== false) {
            return Claim::TYPE_EXTENDED_WARRANTY;
        } elseif (mb_stripos($lossType, mb_strtolower(self::TYPE_WARRANTY)) !== false) {
            return Claim::TYPE_WARRANTY;
        } else {
            return null;
        }
    }

    public function getPolicyNumber()
    {
        if (preg_match('/[^a-zA-Z]*([a-zA-Z]+\/[0-9]{4,4}\/[0-9]{5,20}).*/', $this->policyNumber, $matches) &&
            isset($matches[1])) {
            return $matches[1];
        }

        return null;
    }

    public function isOpen($includeReOpened = false)
    {
        if ($includeReOpened) {
            return in_array(mb_strtolower($this->status), [
                mb_strtolower(self::STATUS_OPEN),
                mb_strtolower(self::STATUS_REOPENED),
                mb_strtolower(self::STATUS_REOPENED_ALT),
            ]);
        } else {
            return in_array(mb_strtolower($this->status), [mb_strtolower(self::STATUS_OPEN)]);
        }
    }

    public function isClosed($includeReClosed = false)
    {
        if ($includeReClosed) {
            return in_array(mb_strtolower($this->status), [
                mb_strtolower(self::STATUS_CLOSED),
                mb_strtolower(self::STATUS_RECLOSED),
                mb_strtolower(self::STATUS_RECLOSED_ALT),
            ]);
        } else {
            return in_array(mb_strtolower($this->status), [mb_strtolower(self::STATUS_CLOSED)]);
        }
    }

    public function getDaviesStatus()
    {
        if (in_array(mb_strtolower($this->status), [
                mb_strtolower(self::STATUS_OPEN),
            ])) {
            return self::STATUS_OPEN;
        } elseif (in_array(mb_strtolower($this->status), [
                mb_strtolower(self::STATUS_CLOSED),
            ])) {
            return self::STATUS_CLOSED;
        } elseif (in_array(mb_strtolower($this->status), [
                mb_strtolower(self::STATUS_REOPENED),
                mb_strtolower(self::STATUS_REOPENED_ALT),
            ])) {
            return self::STATUS_REOPENED;
        } elseif (in_array(mb_strtolower($this->status), [
                mb_strtolower(self::STATUS_RECLOSED),
                mb_strtolower(self::STATUS_RECLOSED_ALT),
            ])) {
            return self::STATUS_RECLOSED;
        }

        return null;
    }

    public function getClaimStatus()
    {
        // open status should not update
        if ($this->isOpen(false)) {
            return null;
        } elseif ($this->isClosed(true)) {
            if ($this->miStatus == self::MISTATUS_SETTLED) {
                return Claim::STATUS_SETTLED;
            } elseif ($this->miStatus == self::MISTATUS_REPUDIATED) {
                return Claim::STATUS_DECLINED;
            } elseif ($this->miStatus == self::MISTATUS_WITHDRAWN) {
                return Claim::STATUS_WITHDRAWN;
            }
        }

        return null;
    }

    public function isApproved()
    {
        // Replacement imei or received date indicate a phone has been dispatched or received
        // Replacement make/model should also indicate but problematic as davies often enteres incorrectly
        // If phone replacement value is negative, an excess payment has been applied to the account
        // If phone replacement value is positive, phone has been paid out
        if ($this->replacementImei || $this->replacementReceivedDate || $this->phoneReplacementCost < 0 ||
            $this->phoneReplacementCost > 0) {
            return true;
        }

        return false;
    }

    public function fromArray($data, $columns)
    {
        try {
            // TODO: Improve validation - should should exceptions in the setters
            $i = 0;
            $this->client = trim($data[$i]);
            if ($this->client !== self::CLIENT_NAME) {
                // We now have lots of other data in the report, so just ignore lines that don't match
                return null;
            }

            if (count($data) != $columns) {
                throw new \Exception(sprintf('Expected %d columns', $columns));
            }

            $this->claimNumber = $this->nullIfBlank($data[++$i]);
            $this->insuredName = $this->nullIfBlank($data[++$i]);
            $this->riskPostCode = $this->nullIfBlank($data[++$i]);
            $this->lossDate = $this->excelDate($data[++$i]);
            $this->startDate = $this->excelDate($data[++$i]);
            $this->endDate = $this->excelDate($data[++$i], true);
            $this->lossType = $this->nullIfBlank($data[++$i]);
            $this->lossDescription = $this->nullIfBlank($data[++$i]);
            $this->location = $this->nullIfBlank($data[++$i]);
            $this->status = $this->nullIfBlank($data[++$i]);
            $this->miStatus = $this->nullIfBlank($data[++$i]);
            $this->brightstarProductNumber = $this->nullIfBlank($data[++$i]);
            $this->replacementMake = $this->nullIfBlank($data[++$i]);
            $this->replacementModel = $this->nullIfBlank($data[++$i]);
            $this->replacementImei = $this->nullIfBlank($data[++$i], 'replacementImei', $this);
            $this->replacementReceivedDate = $this->excelDate($data[++$i], false, true);

            if (in_array($columns, [self::COLUMN_COUNT_V6, self::COLUMN_COUNT_V7, self::COLUMN_COUNT_V8])) {
                $this->phoneReplacementCost = $this->nullIfBlank($data[++$i]);
                $this->phoneReplacementCostReserve = $this->nullIfBlank($data[++$i]);
                $this->accessories = $this->nullIfBlank($data[++$i]);
                $this->accessoriesReserve = $this->nullIfBlank($data[++$i]);
                $this->unauthorizedCalls = $this->nullIfBlank($data[++$i]);
                $this->unauthorizedCallsReserve = $this->nullIfBlank($data[++$i]);
                $this->reciperoFee = $this->nullIfBlank($data[++$i]);
                $this->transactionFees = $this->nullIfBlank($data[++$i]);
                $this->feesReserve = $this->nullIfBlank($data[++$i]);

                $this->reserved = $this->nullIfBlank($data[++$i]);
                $this->incurred = $this->nullIfBlank($data[++$i]);
                $this->handlingFees = $this->nullIfBlank($data[++$i]);
                $this->excess = $this->nullIfBlank($data[++$i]);
            } elseif ($columns == self::COLUMN_COUNT_V1) {
                $this->incurred = $this->nullIfBlank($data[++$i]);
                $this->unauthorizedCalls = $this->nullIfBlank($data[++$i]);
                $this->accessories = $this->nullIfBlank($data[++$i]);
                $this->phoneReplacementCost = $this->nullIfBlank($data[++$i]);
                $this->transactionFees = $this->nullIfBlank($data[++$i]);
                $this->handlingFees = $this->nullIfBlank($data[++$i]);
                $this->excess = $this->nullIfBlank($data[++$i]);
                $this->reserved = $this->nullIfBlank($data[++$i]);
                $this->reciperoFee = $this->nullIfBlank($data[++$i]);
            }
            $this->policyNumber = $this->nullIfBlank($data[++$i]);
            $this->notificationDate = $this->excelDate($data[++$i]);
            $this->dateCreated = $this->excelDate($data[++$i]);
            $this->dateClosed = $this->excelDate($data[++$i]);
            $this->shippingAddress = $this->nullIfBlank($data[++$i]);

            if (in_array($columns, [self::COLUMN_COUNT_V6, self::COLUMN_COUNT_V7, self::COLUMN_COUNT_V8])) {
                $this->totalIncurred = $this->nullIfBlank($data[++$i]);
            }
            if (in_array($columns, [self::COLUMN_COUNT_V7, self::COLUMN_COUNT_V8])) {
                $this->risk = $this->nullIfBlank($data[++$i]);
            }
            if (in_array($columns, [self::COLUMN_COUNT_V8])) {
                $this->daysSinceInception = $this->nullIfBlank($data[++$i]);
                $this->initialSuspicion = $this->isSuspicious($data[++$i]);
                $this->finalSuspicion = $this->isSuspicious($data[++$i]);
            }

            if (!in_array(mb_strtolower($this->status), [
                mb_strtolower(self::STATUS_OPEN),
                mb_strtolower(self::STATUS_CLOSED),
                mb_strtolower(self::STATUS_REOPENED),
                mb_strtolower(self::STATUS_REOPENED_ALT),
                mb_strtolower(self::STATUS_RECLOSED),
                mb_strtolower(self::STATUS_RECLOSED_ALT),
            ])) {
                throw new \Exception('Unknown claim status');
            }

            if ($this->miStatus !== null && !in_array(mb_strtolower($this->miStatus), [
                mb_strtolower(self::MISTATUS_SETTLED),
                mb_strtolower(self::MISTATUS_WITHDRAWN),
                mb_strtolower(self::MISTATUS_REPUDIATED),
                mb_strtolower(self::MISTATUS_ADJUSTER_CORREPONDENCE),
                mb_strtolower(self::MISTATUS_ADJUSTER_FEE),
                mb_strtolower(self::MISTATUS_CLAIMANT_CORRESPONDENCE),
                mb_strtolower(self::MISTATUS_CONTRIBUTION),
                mb_strtolower(self::MISTATUS_RECOVERY),
                mb_strtolower(self::MISTATUS_RETURNED_CHEQUE),
                mb_strtolower(self::MISTATUS_SUPPLIER_CORRESPONDENCE),
                mb_strtolower(self::MISTATUS_SUPPLIER_FEE),
                mb_strtolower(self::MISTATUS_ERROR),
                mb_strtolower(self::MISTATUS_DMS_ERROR),
                mb_strtolower(self::MISTATUS_COMPLAINT),
                mb_strtolower(self::MISTATUS_INSURER),
            ])) {
                throw new \Exception('Unknown claim detail status');
            }

            if ($this->getClaimType() === null) {
                throw new \Exception('Unknown or missing claim type');
            }

            if ($this->replacementImei && !$this->isImei($this->replacementImei) && !$this->isReplacementRepaired()) {
                throw new \Exception(sprintf('Invalid replacement imei %s', $this->replacementImei));
            }
        } catch (\Exception $e) {
            throw new \Exception(sprintf('%s claim: %s %s', $e->getMessage(), json_encode($this), json_encode($data)));
        }

        return true;
    }

    public function isReplacementRepaired()
    {
        if ($this->isReplacementRepair == null) {
            $this->isReplacementRepair = mb_stripos($this->replacementImei, 'repair') != false;
        }

        if ($this->isReplacementRepair) {
            $this->replacementImei = null;
        }

        return $this->isReplacementRepair;
    }

    public static function create($data, $columns)
    {
        $claim = new DaviesClaim();
        if (!$claim->fromArray($data, $columns)) {
            return null;
        }

        return $claim;
    }
}
