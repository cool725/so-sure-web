<?php
namespace AppBundle\Classes;

use AppBundle\Document\Claim;

class DaviesClaim
{
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
    public $incurred;
    public $unauthorizedCalls;
    public $accessories;
    public $phoneReplacementCost;
    public $transactionFees;
    public $handlingFees;
    public $excess;
    public $reserved;
    public $reciperoFee;
    public $policyNumber;
    public $notificationDate;
    public $dateCreated;
    public $dateClosed;

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

    public function isClaimWarrantyOrExtended()
    {
        return in_array($this->getClaimType(), [Claim::TYPE_WARRANTY, Claim::TYPE_EXTENDED_WARRANTY]);
    }

    public function getClaimType()
    {
        if (stripos($this->lossType, "Loss") !== false) {
            return Claim::TYPE_LOSS;
        } elseif (stripos($this->lossType, "Theft") !== false) {
            return Claim::TYPE_THEFT;
        } elseif (stripos($this->lossType, "Damage") !== false) {
            return Claim::TYPE_DAMAGE;
        } elseif (stripos($this->lossType, "Warranty") !== false) {
            return Claim::TYPE_WARRANTY;
        } elseif (stripos($this->lossType, "Extended Warranty") !== false) {
            return Claim::TYPE_EXTENDED_WARRANTY;
        } else {
            return null;
        }
    }

    public function isOpen()
    {
        return strtolower($this->status) == "open";
    }

    public function isClosed()
    {
        return strtolower($this->status) == "closed";
    }

    public function getClaimStatus()
    {
        // open status should not update
        if (strtolower($this->status) == "open") {
            return null;
        } elseif (strtolower($this->status) == "closed" && strtolower($this->miStatus) == "settled") {
            return Claim::STATUS_SETTLED;
        } elseif (strtolower($this->status) == "closed" && strtolower($this->miStatus) == "repudiated") {
            return Claim::STATUS_DECLINED;
        } elseif (strtolower($this->status) == "closed" && strtolower($this->miStatus) == "withdrawn") {
            return Claim::STATUS_WITHDRAWN;
        }
    }

    public function fromArray($data)
    {
        try {
            if (count($data) != 31) {
                throw new \Exception('Excpected 31 columns (Export v5)');
            }

            // TODO: Improve validation - should should exceptions in the setters
            $i = 0;
            $this->client = $data[$i];
            if ($this->client == "") {
                // empty row - ignore
                return;
            } elseif ($this->client != "So-Sure -Mobile") {
                throw new \Exception('Incorrect client');
            }

            $this->claimNumber = $data[++$i];
            $this->insuredName = $data[++$i];
            $this->riskPostCode = $data[++$i];
            $this->lossDate = $this->excelDate($data[++$i]);
            $this->startDate = $this->excelDate($data[++$i]);
            $this->endDate = $this->excelDate($data[++$i]);
            $this->lossType = $data[++$i];
            $this->lossDescription = $data[++$i];
            $this->location = $data[++$i];
            $this->status = $data[++$i];
            $this->miStatus = $data[++$i];
            $this->brightstarProductNumber = $this->nullIfBlank($data[++$i]);
            $this->replacementMake = $this->nullIfBlank($data[++$i]);
            $this->replacementModel = $this->nullIfBlank($data[++$i]);
            $this->replacementImei = $this->nullIfBlank($data[++$i]);
            $this->replacementReceivedDate = $this->excelDate($data[++$i]);
            $this->incurred = $this->nullIfBlank($data[++$i]);
            $this->unauthorizedCalls = $this->nullIfBlank($data[++$i]);
            $this->accessories = $this->nullIfBlank($data[++$i]);
            $this->phoneReplacementCost = $this->nullIfBlank($data[++$i]);
            $this->transactionFees = $this->nullIfBlank($data[++$i]);
            $this->claimHandlingFees = $this->nullIfBlank($data[++$i]);
            $this->excess = $this->nullIfBlank($data[++$i]);
            $this->reserved = $this->nullIfBlank($data[++$i]);
            $this->reciperoFee = $this->nullIfBlank($data[++$i]);
            $this->policyNumber = $data[++$i];
            $this->notificationDate = $this->excelDate($data[++$i]);
            $this->dateCreated = $this->excelDate($data[++$i]);
            $this->dateClosed = $this->excelDate($data[++$i]);
            $this->shippingAddress = $this->nullIfBlank($data[++$i]);
        } catch (\Exception $e) {
            throw new \Exception(sprintf('%s claim: %s', $e->getMessage(), json_encode($this)));
        }
    }

    public static function create($data)
    {
        $claim = new DaviesClaim();
        $claim->fromArray($data);

        if ($claim->client == "") {
            return null;
        }

        return $claim;
    }

    private function nullIfBlank($field)
    {
        if (!$field || $this->isNullableValue($field)) {
            return null;
        }

        return trim($field);
    }

    private function isNullableValue($value)
    {
        // possible values that Davies might use as placeholders
        // when a field is required by their system, but not yet known
        return in_array(trim($value), ['', 'TBC', 'Tbc', 'tbc', '-', '0', 0, 'N/A', 'n/a']);
    }

    private function excelDate($days)
    {
        try {
            if (!$days || $this->isNullableValue($days)) {
                return null;
            }

            $origin = new \DateTime("1900-01-01");
            $origin->add(new \DateInterval(sprintf('P%dD', $days - 2)));

            return $origin;
        } catch (\Exception $e) {
            throw new \Exception(sprintf('Error creating date (days: %s), %s', $days, $e->getMessage()));
        }
    }
}
