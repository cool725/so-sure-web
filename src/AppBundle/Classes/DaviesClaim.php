<?php
namespace AppBundle\Classes;

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
    public $lossType;
    public $lossDescription;
    public $location;
    public $status;
    public $miStatus;
    public $brightstarProductNumber;
    public $replacementMake;
    public $replacementModel;
    public $replacementImei;
    public $unauthorizedCalls;
    public $incurred;
    public $excess;
    public $policyNumber;
    public $notificationDate;
    public $dateCreated;
    public $dateClosed;

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
        $this->unauthorizedCalls = $data[16];
        $this->incurred = $data[17];
        $this->excess = $data[18];
        $this->policyNumber = $data[19];
        $this->notificationDate = $this->excelDate($data[20]);
        $this->dateCreated = $this->excelDate($data[21]);
        $this->dateClosed = $this->excelDate($data[22]);
        $this->shippingAddress = $data[23];
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
