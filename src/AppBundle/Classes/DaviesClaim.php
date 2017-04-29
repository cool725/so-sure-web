<?php
namespace AppBundle\Classes;

use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;

class DaviesClaim
{
    use CurrencyTrait;
    use DateTrait;

    const SHEET_NAME_V6 = 'Cumulative';
    const SHEET_NAME_V1 = 'Original';
    const CLIENT_NAME = "So-Sure -Mobile";
    const COLUMN_COUNT_V1 = 31;
    const COLUMN_COUNT_V6 = 36;

    const STATUS_OPEN = 'Open';
    const STATUS_CLOSED = 'Closed';
    const STATUS_REOPENED = 'Re-Opened';
    const STATUS_RECLOSED = 'Re-Closed';

    const MISTATUS_SETTLED = 'Settled';
    const MISTATUS_WITHDRAWN = 'Withdrawn';
    const MISTATUS_REPUDIATED = 'Repudiated';

    const TYPE_LOSS = 'Loss';
    const TYPE_THEFT = 'Theft';
    const TYPE_DAMAGE = 'Damage';
    const TYPE_WARRANTY = 'Warranty';
    const TYPE_EXTENDED_WARRANTY = 'Extended Warranty';

    public static $sheetNames = [
        self::SHEET_NAME_V6,
        self::SHEET_NAME_V1
    ];

    public static function getColumnsFromSheetName($sheetName)
    {
        if ($sheetName == self::SHEET_NAME_V6) {
            return self::COLUMN_COUNT_V6;
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
    public $lossDate;
    public $startDate;
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

    // davies use only
    public $daviesIncurred;

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

    public function isIncurredValueCorrect()
    {
        $expected = $this->getExpectedIncurred();
        if ($expected === null) {
            return null;
        }

        return $this->areEqualToTwoDp($this->incurred, $this->getExpectedIncurred());
    }

    public function getExpectedIncurred()
    {
        // Incurred fee only appears to be populated at the point where the phone replacement cost is known,
        if (!$this->phoneReplacementCost || $this->areEqualToTwoDp(0, $this->phoneReplacementCost)) {
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
        if (stripos($this->lossType, self::TYPE_LOSS) !== false) {
            return Claim::TYPE_LOSS;
        } elseif (stripos($this->lossType, self::TYPE_THEFT) !== false) {
            return Claim::TYPE_THEFT;
        } elseif (stripos($this->lossType, self::TYPE_DAMAGE) !== false) {
            return Claim::TYPE_DAMAGE;
        } elseif (stripos($this->lossType, self::TYPE_WARRANTY) !== false) {
            return Claim::TYPE_WARRANTY;
        } elseif (stripos($this->lossType, self::TYPE_EXTENDED_WARRANTY) !== false) {
            return Claim::TYPE_EXTENDED_WARRANTY;
        } else {
            return null;
        }
    }

    public function isOpen($includeReOpened = false)
    {
        if ($includeReOpened) {
            return in_array($this->status, [
                self::STATUS_OPEN,
                self::STATUS_REOPENED
            ]);
        } else {
            return in_array($this->status, [self::STATUS_OPEN]);
        }
    }

    public function isClosed($includeReClosed = false)
    {
        if ($includeReClosed) {
            return in_array($this->status, [
                self::STATUS_CLOSED,
                self::STATUS_RECLOSED,
            ]);
        } else {
            return in_array($this->status, [self::STATUS_CLOSED]);
        }
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
            $this->replacementImei = $this->nullIfBlank($data[++$i]);
            $this->replacementReceivedDate = $this->excelDate($data[++$i]);

            if ($columns == self::COLUMN_COUNT_V6) {
                $this->phoneReplacementCost = $this->nullIfBlank($data[++$i]);
                $this->phoneReplacementCostReserved = $this->nullIfBlank($data[++$i]);
                $this->accessories = $this->nullIfBlank($data[++$i]);
                $this->accessoriesReserved = $this->nullIfBlank($data[++$i]);
                $this->unauthorizedCalls = $this->nullIfBlank($data[++$i]);
                $this->unauthorizedCallsReserved = $this->nullIfBlank($data[++$i]);
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

            if ($columns == self::COLUMN_COUNT_V6) {
                $this->daviesIncurred = $this->nullIfBlank($data[++$i]);
            }

            if (!in_array($this->status, [
                self::STATUS_OPEN,
                self::STATUS_CLOSED,
                self::STATUS_REOPENED,
                self::STATUS_RECLOSED,
            ])) {
                throw new \Exception('Unknown claim status');
            }

            if ($this->miStatus !== null && !in_array($this->miStatus, [
                self::MISTATUS_SETTLED,
                self::MISTATUS_WITHDRAWN,
                self::MISTATUS_REPUDIATED,
            ])) {
                throw new \Exception('Unknown claim detail status');
            }

            if ($this->getClaimType() === null) {
                throw new \Exception('Unknown or missing claim type');
            }
        } catch (\Exception $e) {
            throw new \Exception(sprintf('%s claim: %s', $e->getMessage(), json_encode($this)));
        }

        return true;
    }

    public static function create($data, $columns)
    {
        $claim = new DaviesClaim();
        if (!$claim->fromArray($data, $columns)) {
            return null;
        }

        return $claim;
    }

    private function nullIfBlank($field)
    {
        if (!$field || $this->isNullableValue($field)) {
            return null;
        }

        return str_replace('£', '', trim($field));
    }

    private function isNullableValue($value)
    {
        // possible values that Davies might use as placeholders
        // when a field is required by their system, but not yet known
        return in_array(trim($value), ['', 'Unknown', 'TBC', 'Tbc', 'tbc', '-', '0', 'N/A', 'n/a']);
    }

    private function excelDate($days, $skipEndCheck = false)
    {
        try {
            if (!$days || $this->isNullableValue($days)) {
                return null;
            }

            if (!is_numeric($days)) {
                // unfortunately davies is incapable of formatting dates
                // so may be an excel date or may be a d/m/Y formatted string
                $date = \DateTime::createFromFormat('d/m/Y', $days);
                if (!$date instanceof \DateTime) {
                    throw new \Exception('Unable to parse date');
                }
                $origin = $this->startOfDay($date);
            } else {
                $origin = new \DateTime("1900-01-01");
                $origin->add(new \DateInterval(sprintf('P%dD', $days - 2)));
            }

            $minDate = new \DateTime(SoSure::POLICY_START);
            $now = new \DateTime();

            if ($origin < $minDate || ($origin > $now && !$skipEndCheck)) {
                throw new \Exception(sprintf('Out of range for date %s', $origin->format(\DateTime::ATOM)));
            }

            return $origin;
        } catch (\Exception $e) {
            throw new \Exception(sprintf('Error creating date (days: %s), %s', $days, $e->getMessage()));
        }
    }
}
