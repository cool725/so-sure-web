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
    public $excess;
    public $reserved;
    public $policyNumber;
    public $notificationDate;
    public $dateCreated;
    public $dateClosed;

    public function getClaimType()
    {
        if ($this->lossType == "Loss") {
            return Claim::TYPE_LOSS;
        } elseif ($this->lossType == "Theft") {
            return Claim::TYPE_THEFT;
        } elseif ($this->lossType == "Damage") {
            return Claim::TYPE_DAMAGE;
        } elseif ($this->lossType == "Warranty") {
            return Claim::TYPE_WARRANTY;
        } elseif ($this->lossType == "Extended Warranty") {
            return Claim::TYPE_EXTENDED_WARRANTY;
        } else {
            return null;
        }
    }

    public function getClaimStatus()
    {
        // open status should not update
        if ($this->status == "Open") {
            return null;
        } elseif ($this->status == "Closed" && $this->miStatus == "settled") {
            return Claim::STATUS_SETTLED;
        } elseif ($this->status == "Closed" && $this->miStatus == "repudiated") {
            return Claim::STATUS_DECLINED;
        } elseif ($this->status == "Closed" && $this->miStatus == "withdrawn") {
            return Claim::STATUS_WITHDRAWN;
        }
    }

    public function fromArray($data)
    {
        // TODO: Improve validation - should should exceptions in the setters
        $this->client = $data[0];
        if ($this->client == "") {
            return;
        } elseif ($this->client != "So-Sure") {
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
        $this->brightstarProductNumber = $data[12];
        $this->replacementMake = $data[13];
        $this->replacementModel = $data[14];
        $this->replacementImei = $data[15];
        $this->replacementReceivedDate = $this->excelDate($data[16]);
        $this->incurred = $data[17];
        $this->unauthorizedCalls = $data[18];
        $this->accessories = $data[19];
        $this->phoneReplacementCost = $data[20];
        $this->transactionFees = $data[21];
        $this->excess = $data[22];
        $this->reserved = $data[23];
        $this->policyNumber = $data[24];
        $this->notificationDate = $this->excelDate($data[25]);
        $this->dateCreated = $this->excelDate($data[26]);
        $this->dateClosed = $this->excelDate($data[27]);
        $this->shippingAddress = $data[28];
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

    private function excelDate($days)
    {
        $origin = new \DateTime("1900-01-01");
        $origin->add(new \DateInterval(sprintf('P%dD', $days - 2)));

        return $origin;
    }
}
