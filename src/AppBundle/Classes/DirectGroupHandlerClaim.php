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
    const COLUMN_COUNT_V1 = 43;

    const STATUS_OPEN = 'Open';
    const STATUS_CLOSED = 'Paid Closed';

    // Not used much, but will be present in the file (will be wrapped into the closed status)
    const STATUS_CLOSED_REPAIRED = 'Paid Closed - Repaired';
    // Not used much, but will be present in the file (will be wrapped into the closed status)
    const STATUS_CLOSED_REPLACED = 'Paid Closed - Replaced';

    const STATUS_WITHDRAWN = 'Withdrawn';
    const STATUS_REJECTED = 'Rejected';

    const DETAIL_STATUS_WITHDRAW_NO_DOC = 'Claim Documentation not provided'; // WITHDRAW01
    const DETAIL_STATUS_WITHDRAW_NO_INTERVIEW = 'Claim Interview not completed'; // WITHDRAW02
    const DETAIL_STATUS_WITHDRAW_NO_CONF_CALL = 'Conference Call not completed'; // WITHDRAW03
    const DETAIL_STATUS_WITHDRAW_BY_REQUEST = 'Customer Request'; // WITHDRAW04
    const DETAIL_STATUS_WITHDRAW_MISSING_EXCESS = 'Excess Fee not paid'; // WITHDRAW06
    const DETAIL_STATUS_WITHDRAW_WARRANTY = 'Repaired/Replaced under warranty'; // WITHDRAW07
    const DETAIL_STATUS_WITHDRAW_FOUND = 'Item Returned/Found'; // WITHDRAW08
    const DETAIL_STATUS_WITHDRAW_WORKING = 'Item started working again'; // WITHDRAW09
    const DETAIL_STATUS_WITHDRAW_NO_OWNERSHIP = 'Evidence of ownership not provided'; // WITHDRAW10
    const DETAIL_STATUS_WITHDRAW_UPGRADE = 'Customer received an upgrade'; // WITHDRAW12
    const DETAIL_STATUS_WITHDRAW_MISSING_INFO = 'Unable to provide req. info'; // WITHDRAW13
    const DETAIL_STATUS_WITHDRAW_NO_REPAIR = 'Item not sent for repair'; // WITHDRAW14
    const DETAIL_STATUS_WITHDRAW_REPLACED_ELSEWHERE = 'Replaced Elsewhere'; // WITHDRAW15
    const DETAIL_STATUS_WITHDRAW_FNOL_NOT_PROGRESS = 'Not Processed (FNOL)'; // WITHDRAW16
    const DETAIL_STATUS_WITHDRAW_TOO_LONG = 'Process Too Long'; // WITHDRAW17
    const DETAIL_STATUS_WITHDRAW_NOT_INSURED = 'Item Not Insured With US'; // WITHDRAW19
    const DETAIL_STATUS_WITHDRAW_ENQUIRY = 'No Claim / Enquiry only'; // WITHDRAW20
    const DETAIL_STATUS_WITHDRAW_HIGH_COST = 'Not cost effective'; // WITHDRAW21

    const DETAIL_STATUS_REJECTED_BAD_OWNERSHIP = 'Evidence of Ownership not accepted'; // REJECT - 01
    const DETAIL_STATUS_REJECTED_BAD_INFORMATION = 'Mismatch of Information'; // REJECT - 02
    const DETAIL_STATUS_REJECTED_TOO_OLD = 'Item insured Too old'; // REJECT - 03
    const DETAIL_STATUS_REJECTED_SECONDHAND = 'Item insured is second hand/ refurbished'; // REJECT - 04
    const DETAIL_STATUS_REJECTED_UNAUTH_REPAIR = 'Unauthorised Repair'; // REJECT - 05
    const DETAIL_STATUS_REJECTED_UNATTENDED = 'Left Unattended'; // REJECT - 06
    const DETAIL_STATUS_REJECTED_UNREASONABLE = 'Unreasonable /Not used all available precautions'; // REJECT - 08
    const DETAIL_STATUS_REJECTED_NOT_COVERED = 'Loss Not Covered'; // REJECT - 09
    const DETAIL_STATUS_REJECTED_OUTSIDE_COVER = 'Incident Outside policy cover'; // REJECT - 10
    const DETAIL_STATUS_REJECTED_FAMILY = 'Immediate Family'; // REJECT - 11
    const DETAIL_STATUS_REJECTED_NO_PROOF_USAGE = 'No Proof of Usage'; // REJECT - 12
    const DETAIL_STATUS_REJECTED_NO_FORCED_ENTRY = 'No Forced Entry'; // REJECT - 13
    const DETAIL_STATUS_REJECTED_NOT_IN_USE = 'Item Not In Use'; // REJECT - 15
    const DETAIL_STATUS_REJECTED_FIRST_14_DAYS = 'Incident occurred within first 14 days'; // REJECT - 16
    const DETAIL_STATUS_REJECTED_VEHICLE_NOT_CONCEALED = 'Motor Vehicle not concealed'; // REJECT - 17
    const DETAIL_STATUS_REJECTED_NOT_GADGET = 'Item Not Insurer with Us / Item Not Considered a Gadget'; // REJECT - 18
    const DETAIL_STATUS_REJECTED_LATE_POLICE = 'Late to Police/No Police Report'; // REJECT - 21
    const DETAIL_STATUS_REJECTED_MAX_CLAIMS = 'Maximum Claims Reached'; // REJECT - 22
    const DETAIL_STATUS_REJECTED_OTHER_INSURER = 'Customer claiming with another insurer'; // REJECT - 24
    const DETAIL_STATUS_REJECTED_USE_BEFORE_PURCHASE = 'Last Use before Purchase Date'; // REJECT - 25
    const DETAIL_STATUS_REJECTED_NO_SIM = 'Non Original SIM/SIM Not Present'; // REJECT - 26
    const DETAIL_STATUS_REJECTED_UNPAID = 'Payments in arrears'; // REJECT - 27
    const DETAIL_STATUS_REJECTED_UNCLEAR = 'Unclear Circumstances'; // REJECT - 28
    const DETAIL_STATUS_REJECTED_COSMETIC = 'Wear and Tear/Cosmetic damages'; // REJECT - 29

    const SUPPLIER_MASTERCARD = 'Mastercard';

    public $replacementSupplier;
    public $repairSupplier;
    public $supplierStatus;

    public static $breakdownEmailAddresses = [
        'Robert.Hodson@directgroup.co.uk',
        'Tracey.McManus@directgroup.co.uk',
    ];

    public static $errorEmailAddresses = [
        'Robert.Hodson@directgroup.co.uk',
        'alex@so-sure.com',
        'dylan@so-sure.com',
        'Sally.Hancock@directgroup.co.uk',
        'Sharon.Nolan@directgroup.co.uk',
        'kitti@so-sure.com'
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

    public function setTotalIncurred($totalIncurred)
    {
        $this->totalIncurred = $totalIncurred + $this->handlingFees - $this->excess;
    }

    public function getIncurred()
    {
        if (!$this->totalIncurred) {
            return 0;
        }

        // No incurred value is present, but incurred is total (which inc excess) - cost of claim (handling fees)
        return $this->totalIncurred - $this->handlingFees;
    }

    public function hasError()
    {
        return false;
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



    public function getClaimType()
    {
        $lossType = mb_strtolower($this->lossType);
        if (mb_stripos($lossType, mb_strtolower(self::TYPE_LOSS)) !== false) {
            return Claim::TYPE_LOSS;
        } elseif (mb_stripos($lossType, mb_strtolower(self::TYPE_THEFT)) !== false) {
            return Claim::TYPE_THEFT;
        } elseif (mb_stripos($lossType, mb_strtolower(self::TYPE_DAMAGE)) !== false) {
            return Claim::TYPE_DAMAGE;
        } elseif (mb_stripos($lossType, 'Breakdown') !== false) {
            return Claim::TYPE_WARRANTY;
        } elseif (mb_stripos($lossType, 'Impact') !== false) {
            return Claim::TYPE_DAMAGE;
        } elseif (mb_stripos($lossType, 'Water') !== false) {
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

    public function getStatus()
    {
        if (mb_stripos($this->status, self::STATUS_OPEN) !== false) {
            return self::STATUS_OPEN;
        } elseif (mb_stripos($this->status, self::STATUS_CLOSED) !== false) {
            return self::STATUS_CLOSED;
        } elseif (mb_stripos($this->status, self::STATUS_CLOSED) !== false) {
            return self::STATUS_CLOSED;
        } elseif (mb_stripos($this->status, self::STATUS_WITHDRAWN) !== false) {
            return self::STATUS_WITHDRAWN;
        } elseif (mb_stripos($this->status, self::STATUS_REJECTED) !== false) {
            return self::STATUS_REJECTED;
        }

        return null;
    }

    public function isOpen($includeReOpened = false)
    {
        \AppBundle\Classes\NoOp::ignore([$includeReOpened]);

        return in_array(mb_strtolower($this->getStatus()), [
            mb_strtolower(self::STATUS_OPEN)
        ]);
    }

    public function isClosed($includeReClosed = false)
    {
        \AppBundle\Classes\NoOp::ignore([$includeReClosed]);

        return in_array(mb_strtolower($this->getStatus()), [
            mb_strtolower(self::STATUS_CLOSED),
            mb_strtolower(self::STATUS_WITHDRAWN),
            mb_strtolower(self::STATUS_REJECTED)
        ]);
    }

    public function getClaimStatus()
    {
        // open status should not update
        if ($this->isOpen()) {
            return null;
        } elseif ($this->isClosed()) {
            if (in_array(mb_strtolower($this->getStatus()), [mb_strtolower(self::STATUS_CLOSED)])) {
                return Claim::STATUS_SETTLED;
            } elseif (in_array(mb_strtolower($this->getStatus()), [mb_strtolower(self::STATUS_REJECTED)])) {
                return Claim::STATUS_DECLINED;
            } elseif (in_array(mb_strtolower($this->getStatus()), [mb_strtolower(self::STATUS_WITHDRAWN)])) {
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

    public function getExpectedIncurred()
    {
        // Incurred fee only appears to be populated at the point where the phone replacement cost is known,
        if (!$this->phoneReplacementCost || $this->phoneReplacementCost < 0 ||
            $this->areEqualToTwoDp(0, $this->phoneReplacementCost)) {
            return null;
        }

        $total = $this->unauthorizedCalls + $this->accessories + $this->phoneReplacementCost - $this->excess;

        return $this->toTwoDp($total);
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

            $this->policyNumber = str_replace('MOB', 'Mob', $this->nullIfBlank($data[++$i]));
            $this->claimNumber = $this->nullIfBlank($data[++$i]);
            $this->insuredName = $this->nullIfBlank($data[++$i]);
            $this->riskPostCode = $this->nullIfBlank($data[++$i]);
            $this->risk = $this->nullIfBlank($data[++$i]);
            $this->startDate = $this->excelDate($data[++$i]);
            $this->endDate = $this->excelDate($data[++$i], true);
            $this->initialSuspicion = $this->isSuspicious($data[++$i]);
            // todo: Claim Type Suspicious notes  captured at time report is run
            $this->nullIfBlank($data[++$i]);
            $this->lossDate = $this->excelDate($data[++$i]);
            $this->notificationDate = $this->excelDate($data[++$i]);
            $this->dateCreated = $this->excelDate($data[++$i]);
            // todo: Date of claim decision
            $this->excelDate($data[++$i]);
            $this->dateClosed = $this->excelDate($data[++$i]);
            $this->lossType = $this->nullIfBlank($data[++$i]);
            $this->lossDescription = $this->nullIfBlank($data[++$i]);
            $this->location = $this->nullIfBlank($data[++$i]);
            $this->status = $this->nullIfBlank($data[++$i]);
            // todo: detailed status
            $this->nullIfBlank($data[++$i]);
            // todo: Latest Claim handling team touch point date
            $this->excelDate($data[++$i]);
            $this->replacementSupplier = $this->nullIfBlank($data[++$i]);
            $this->repairSupplier = $this->nullIfBlank($data[++$i]);
            $this->supplierStatus = $this->nullIfBlank($data[++$i]);
            // todo: Supplier pick up date
            $this->excelDate($data[++$i], true);
            // todo: Supplier repair date
            $this->excelDate($data[++$i]);
            $this->replacementReceivedDate = $this->excelDate($data[++$i]);
            $this->replacementMake = $this->nullIfBlank($data[++$i]);
            $this->replacementModel = $this->nullIfBlank($data[++$i]);
            $this->replacementImei = $this->nullImeiIfBlank($data[++$i]);
            $this->shippingAddress = $this->nullIfBlank($data[++$i]);
            $this->phoneReplacementCost = $this->nullIfBlank($data[++$i]);
            $this->phoneReplacementCostReserve = $this->nullIfBlank($data[++$i]);
            $this->accessories = $this->nullIfBlank($data[++$i]);
            $this->accessoriesReserve = $this->nullIfBlank($data[++$i]);
            $this->unauthorizedCalls = $this->nullIfBlank($data[++$i]);
            $this->unauthorizedCallsReserve = $this->nullIfBlank($data[++$i]);
            $this->reserved = $this->nullIfBlank($data[++$i]);
            $this->handlingFees = $this->nullIfBlank($data[++$i]);
            $this->excess = $this->nullIfBlank($data[++$i]);
            // todo: KFI score
            $this->nullIfBlank($data[++$i]);
            $this->setTotalIncurred($this->nullIfBlank($data[++$i]));

            if ($this->getClaimType() === null) {
                throw new \Exception('Unknown or missing claim type');
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

    public function isNullableValue($value)
    {
        // possible values that Direct Group might use as placeholders
        // when a field is required by their system, but not yet known
        return in_array(trim($value), ['']);
    }

    public function isUnobtainableValue($value)
    {
        // possible values that Direct Group might use as placeholders
        // when a field is required by their system, but data will never be provided
        if (mb_stripos($value, 'unable to obtain') !== false) {
            return true;
        }

        return false;
    }

    public function isReplacementRepaired()
    {
        // if repair supplier is present, then its a repair and imei will not be present
        return mb_strlen($this->repairSupplier) > 0 || $this->status == self::STATUS_CLOSED_REPAIRED;
    }

    public function isPhoneReplacementCostCorrect()
    {
        if (parent::isPhoneReplacementCostCorrect()) {
            return true;
        }

        return $this->isMastercardPhoneReplacement();
    }

    protected function isMastercardPhoneReplacement()
    {
        // odd case for dg where mastercard appears under the accessories instead of phone replacement
        return $this->accessories > 0 &&
            mb_strtolower($this->replacementSupplier) == mb_strtolower(self::SUPPLIER_MASTERCARD);
    }

    protected function isSuspicious($field)
    {
        if (!$field || $this->isNullableValue($field)) {
            return null;
        }

        if (in_array(mb_strtolower($field), ['ok'])) {
            return false;
        } elseif (in_array(mb_strtolower($field), ['concerns'])) {
            return true;
        }

        return null;
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
