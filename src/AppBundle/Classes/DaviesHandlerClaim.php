<?php
namespace AppBundle\Classes;

use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ImeiTrait;

class DaviesHandlerClaim extends HandlerClaim
{
    use CurrencyTrait;
    use DateTrait;
    use ImeiTrait;
    use ExcelTrait;

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
    const MISTATUS_UNDERWRITER = "Contact From Underwriter";

    const TYPE_LOSS = 'Loss';
    const TYPE_THEFT = 'Theft';
    const TYPE_DAMAGE = 'Damage';
    const TYPE_WARRANTY = 'Warranty';
    const TYPE_EXTENDED_WARRANTY = 'Extended Warranty';

    public $brightstarProductNumber;
    public $reciperoFee;
    public $transactionFees;

    public static $breakdownEmailAddresses = [
        'julien@so-sure.com',
    ];

    public static $invoiceEmailAddresses = [
        'julien@so-sure.com',
        'dylan@so-sure.com',
    ];

    public static $errorEmailAddresses = [
        'julien@so-sure.com',
        'dylan@so-sure.com',
        'kitti@so-sure.com'
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

    public function hasError()
    {
        return in_array(mb_strtolower($this->miStatus), [
            mb_strtolower(self::MISTATUS_ERROR),
            mb_strtolower(self::MISTATUS_DMS_ERROR),
        ]);
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
            $this->replacementImei = $this->nullImeiIfBlank($data[++$i]);
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
                mb_strtolower(self::MISTATUS_UNDERWRITER),
            ])) {
                throw new \Exception('Unknown claim detail status');
            }

            if ($this->getClaimType() === null) {
                throw new \Exception('Unknown or missing claim type');
            }

            $this->checkReplacementRepaired();
        } catch (\Exception $e) {
            throw new \Exception(sprintf(
                '<b>%s</b> Data Imported: <small>%s</small> Excel Record: <small>%s</small>',
                $e->getMessage(),
                json_encode($this),
                json_encode($data)
            ));
        }

        return true;
    }

    public function checkReplacementRepaired()
    {
        if ($this->isReplacementRepaired()) {
            $this->replacementImei = null;
        }
    }

    public function isReplacementRepaired()
    {
        if ($this->isReplacementRepair == null) {
            $this->isReplacementRepair = mb_stripos($this->replacementImei, 'repair') != false;
        }

        return $this->isReplacementRepair;
    }

    public static function create($data, $columns)
    {
        $claim = new DaviesHandlerClaim();
        if (!$claim->fromArray($data, $columns)) {
            return null;
        }

        return $claim;
    }


    protected function isSuspicious($field)
    {
        if (!$field || $this->isNullableValue($field)) {
            return null;
        }

        if (in_array(mb_strtolower($field), ['ok'])) {
            return false;
        } elseif (in_array(mb_strtolower($field), ['suspicious'])) {
            return true;
        }

        return null;
    }

    public function isNullableValue($value)
    {
        // possible values that Davies might use as placeholders
        // when a field is required by their system, but not yet known
        return in_array(trim($value), ['', 'Unknown', 'TBC', 'Tbc', 'tbc', '-', '0',
            'N/A', 'n/a', 'NA', 'na', '#N/A', 'Not Applicable']);
    }

    public function isUnobtainableValue($value)
    {
        // possible values that Davies might use as placeholders
        // when a field is required by their system, but data will never be provided
        return in_array(trim(mb_strtolower($value)), ['unable to obtain']);
    }
}
