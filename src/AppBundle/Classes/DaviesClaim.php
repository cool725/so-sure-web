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
    public $policyNumber;
    public $notificationDate;
    public $dateCreated;
    public $dateClosed;

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
            // TODO: Improve validation - should should exceptions in the setters
            $this->client = $data[0];
            if ($this->client == "") {
                return;
            } elseif ($this->client != "So-Sure -Mobile") {
                throw new \Exception('Incorrect client');
            }

            $this->claimNumber = $data[1];
            $this->insuredName = $data[2];
            $this->riskPostCode = $data[3];
            $this->lossDate = $this->excelDate($data[4]);
            $this->startDate = $this->excelDate($data[5]);
            $this->endDate = $this->excelDate($data[6]);
            $this->lossType = $data[7];
            $this->lossDescription = $data[8];
            $this->location = $data[9];
            $this->status = $data[10];
            $this->miStatus = $data[11];
            $this->brightstarProductNumber = $this->nullIfBlank($data[12]);
            $this->replacementMake = $this->nullIfBlank($data[13]);
            $this->replacementModel = $this->nullIfBlank($data[14]);
            $this->replacementImei = $this->nullIfBlank($data[15]);
            $this->replacementReceivedDate = $this->excelDate($data[16]);
            $this->incurred = $this->nullIfBlank($data[17]);
            $this->unauthorizedCalls = $this->nullIfBlank($data[18]);
            $this->accessories = $this->nullIfBlank($data[19]);
            $this->phoneReplacementCost = $this->nullIfBlank($data[20]);
            $this->transactionFees = $this->nullIfBlank($data[21]);
            $this->claimHandlingFees = $this->nullIfBlank($data[22]);
            $this->excess = $this->nullIfBlank($data[23]);
            $this->reserved = $this->nullIfBlank($data[24]);
            $this->policyNumber = $data[25];
            $this->notificationDate = $this->excelDate($data[26]);
            $this->dateCreated = $this->excelDate($data[27]);
            $this->dateClosed = $this->excelDate($data[28]);
            $this->shippingAddress = $this->nullIfBlank($data[29]);
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
