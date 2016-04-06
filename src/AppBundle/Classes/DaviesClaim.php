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
    public $status;
    public $miStatus;
    public $replacementMake;
    public $replacementModel;
    public $brightstarProductNumber;
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
        $this->claimNumber = $data[1];
        $this->insuredName = $data[2];
        $this->riskPostCode = $data[3];
        $this->shippingAddress = $data[4];
        $this->lossDate = $this->excelDate($data[5]);
        $this->startDate = $this->excelDate($data[6]);
        $this->endDate = $this->excelDate($data[7]);
        $this->lossType = $data[8];
        $this->lossDescription = $data[9];
        $this->status = $data[10];
        $this->miStatus = $data[11];
        $this->replacementMake = $data[12];
        $this->replacementModel = $data[13];
        $this->brightstarProductNumber = $data[14];
        $this->incurred = $data[15];
        $this->excess = $data[16];
        $this->policyNumber = $data[17];
        $this->notificationDate = $this->excelDate($data[18]);
        $this->dateCreated = $this->excelDate($data[19]);
        $this->dateClosed = $this->excelDate($data[20]);
    }

    private function excelDate($days)
    {
        $origin = new \DateTime("1900-01-01");
        $origin->add(new \DateInterval(sprintf('P%dD', $days - 2)));

        return $origin;
    }
}
