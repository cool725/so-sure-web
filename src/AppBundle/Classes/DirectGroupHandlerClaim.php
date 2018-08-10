<?php
namespace AppBundle\Classes;

use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\ImeiTrait;

class DirectGroupHandlerClaim extends HandlerClaim
{
    use CurrencyTrait;
    use DateTrait;
    use ImeiTrait;
    use ExcelTrait;

    const SHEET_NAME_V1 = 'Report';
    const CLIENT_NAME = "SO-SURE";
    const COLUMN_COUNT_V1 = 31;

    const STATUS_OPEN = 'Open';
    const STATUS_CLOSED = 'Paid Closed';

    const DETAIL_STATUS_FNOL_NOT_PROGRESS = 'Not Progressed (Fnol)';
    const DETAIL_STATUS_REPAIRED = 'Item repaired elsewhere';
    const DETAIL_STATUS_FOUND = 'Item Returned/Found';

    public static $breakdownEmailAddresses = [
        'SoSure@directgroup.co.uk',
    ];

    public static $errorEmailAddresses = [
        'SoSure@directgroup.co.uk',
        'patrick@so-sure.com',
        'dylan@so-sure.com',
    ];

    public static $sheetNames = [
        self::SHEET_NAME_V1
    ];

    public static function getColumnsFromSheetName($sheetName)
    {
        if ($sheetName == self::SHEET_NAME_V1) {
            return self::COLUMN_COUNT_V1;
        } else {
            throw new \Exception(sprintf('Unknown sheet name %s', $sheetName));
        }
    }

    public function hasError()
    {
        return false;
    }


    public function getReplacementPhoneDetails()
    {
        return null;
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
            ]);
        } else {
            return in_array(mb_strtolower($this->status), [mb_strtolower(self::STATUS_CLOSED)]);
        }
    }

    public function getDirectStatus()
    {
        if (in_array(mb_strtolower($this->status), [
            mb_strtolower(self::STATUS_OPEN),
        ])) {
            return self::STATUS_OPEN;
        } elseif (in_array(mb_strtolower($this->status), [
            mb_strtolower(self::STATUS_CLOSED),
        ])) {
            return self::STATUS_CLOSED;
        }

        return null;
    }

    public function getClaimStatus()
    {
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

            $this->policyNumber = $this->nullIfBlank($data[++$i]);
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
            $this->notificationDate = $this->excelDate($data[++$i]);
            $this->dateCreated = $this->excelDate($data[++$i]);
            $this->dateClosed = $this->excelDate($data[++$i]);
            $this->shippingAddress = $this->nullIfBlank($data[++$i]);

            if ($this->getClaimType() === null) {
                throw new \Exception('Unknown or missing claim type');
            }

            if ($this->replacementImei && !$this->isImei($this->replacementImei) && !$this->isReplacementRepaired()) {
                throw new \Exception(sprintf('Invalid replacement imei %s', $this->replacementImei));
            }
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

        return str_replace('£', '', trim($field));
    }

    protected function isNullableValue($value)
    {
        // possible values that Direct Group might use as placeholders
        // when a field is required by their system, but not yet known
        return in_array(trim($value), ['']);
    }

    protected function isUnobtainableValue($value)
    {
        // possible values that Direct Group might use as placeholders
        // when a field is required by their system, but data will never be provided
        // return in_array(trim(mb_strtolower($value)), ['unable to obtain']);
        return false;
    }

    public function isReplacementRepaired()
    {
        return false;
    }

    public static function create($data, $columns)
    {
        $claim = new DirectGroupHandlerClaim();
        if (!$claim->fromArray($data, $columns)) {
            return null;
        }

        return $claim;
    }
}
