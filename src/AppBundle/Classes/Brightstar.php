<?php
namespace AppBundle\Classes;

use AppBundle\Document\Address;
use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;

class Brightstar
{
    use CurrencyTrait;
    use DateTrait;

    const SHEET_NAME_V1 = 'So Sure Claims';
    const COLUMN_COUNT_V1 = 40;

    const SERVICE_TYPE_SWAP = 'swap';
    const SERVICE_TYPE_DELIVER = 'deliver';

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

    public $orderDate;
    public $claimNumber;
    public $make;
    public $model;
    public $name;
    public $address1;
    public $address2;
    public $address3;
    public $city;
    public $postcode;
    public $phoneNumber;
    public $email;
    public $service;
    public $timeslot;

    public $orderNumber;
    public $claimValue;
    public $productCode;
    public $description;
    public $replacementImei;
    public $replacementReceivedDate;
    public $dpdTracking;
    
    public $returningImei;
    public $receivedBack;
    public $returnCallNumber;
    public $fmiActive;
    public $handsetValue;
    public $repairable;
    public $comments;
    
    public $faultDate;
    public $faultDetails;
    public $faultReport;
    public $faultReplacementOrderNumber;
    public $faultReplacementProductCode;
    public $faultReplacementDescription;
    public $faultReplacementImei;
    public $faultReplacementDeliveryReceivedDate;
    public $faultReplacementDpdTracking;

    public function getAddress()
    {
        $address = new Address();
        $address->setLine1($this->address1);
        $address->setLine2($this->address2);
        $address->setLine3($this->address3);
        $address->setCity($this->city);
        $address->setPostcode($this->postcode);

        return $address;
    }

    public function getServiceType()
    {
        if (mb_stripos(mb_strtolower($this->service), mb_strtolower(self::SERVICE_TYPE_DELIVER)) !== false) {
            return self::SERVICE_TYPE_DELIVER;
        } elseif (mb_stripos(mb_strtolower($this->service), mb_strtolower(self::SERVICE_TYPE_SWAP)) !== false) {
            return self::SERVICE_TYPE_SWAP;
        } else {
            return null;
        }
    }

    public function fromArray($data, $columns)
    {
        try {
            // TODO: Improve validation - should should exceptions in the setters
            $i = -1;

            if (count($data) != $columns) {
                throw new \Exception(sprintf('Expected %d columns', $columns));
            }
            // Ignore header
            if ($data[0] == "Order Date") {
                return;
            }

            // require claim Number to do anything - otherwise assume empty line
            if ($data[1] == "") {
                return;
            }

            $this->orderDate = $this->excelDate($data[++$i]);
            $this->claimNumber = $this->nullIfBlank($data[++$i]);
            $this->make = $this->nullIfBlank($data[++$i]);
            $this->model = $this->nullIfBlank($data[++$i]);
            $this->name = $this->nullIfBlank($data[++$i]);
            $this->address1 = $this->nullIfBlank($data[++$i]);
            $this->address2 = $this->nullIfBlank($data[++$i]);
            $this->address3 = $this->nullIfBlank($data[++$i]);
            $this->city = $this->nullIfBlank($data[++$i]);
            $this->postcode = $this->nullIfBlank($data[++$i]);
            $this->phoneNumber = $this->nullIfBlank($data[++$i]);
            $this->email = $this->nullIfBlank($data[++$i]);
            $this->service = $this->nullIfBlank($data[++$i]);
            $this->timeslot = $this->nullIfBlank($data[++$i]);
            $i++;
            $this->orderNumber = $this->nullIfBlank($data[++$i]);
            $this->claimValue = $this->nullIfBlank($data[++$i]);
            $this->productCode = $this->nullIfBlank($data[++$i]);
            $this->description = $this->nullIfBlank($data[++$i]);
            $this->replacementImei = $this->nullIfBlank($data[++$i]);
            $this->replacementReceivedDate = $this->excelDate($data[++$i]);
            $this->dpdTracking = $this->nullIfBlank($data[++$i]);
            $i++;
            $this->returningImei = $this->nullIfBlank($data[++$i]);
            $this->receivedBack = $this->nullIfBlank($data[++$i]);
            $this->returnCallNumber = $this->nullIfBlank($data[++$i]);
            $this->fmiActive = $this->nullIfBlank($data[++$i]);
            $this->handsetValue = $this->nullIfBlank($data[++$i]);
            $this->repairable = $this->nullIfBlank($data[++$i]);
            $this->comments = $this->nullIfBlank($data[++$i]);
            $i++;
            $this->faultDate = $this->excelDate($data[++$i]);
            $this->faultDetails = $this->nullIfBlank($data[++$i]);
            $this->faultReport = $this->nullIfBlank($data[++$i]);
            $this->faultReplacementOrderNumber = $this->nullIfBlank($data[++$i]);
            $this->faultReplacementProductCode = $this->nullIfBlank($data[++$i]);
            $this->faultReplacementDescription = $this->nullIfBlank($data[++$i]);
            $this->faultReplacementImei = $this->nullIfBlank($data[++$i]);
            $this->faultReplacementDeliveryReceivedDate = $this->excelDate($data[++$i]);
            $this->faultReplacementDpdTracking = $this->nullIfBlank($data[++$i]);
        } catch (\Exception $e) {
            throw new \Exception(sprintf('%s brightstar data: %s', $e->getMessage(), json_encode($this)));
        }

        return true;
    }

    protected function nullIfBlank($field)
    {
        if (!$field || $this->isNullableValue($field)) {
            return null;
        }

        return str_replace('£', '', trim($field));
    }

    protected function isNullableValue($value)
    {
        // possible values that Davies might use as placeholders
        // when a field is required by their system, but not yet known
        return in_array(trim($value), ['', 'Unknown', 'TBC', 'Tbc', 'tbc', '-', '0',
            'N/A', 'n/a', 'NA', 'na', '#N/A', 'Not Applicable']);
    }

    public static function create($data, $columns)
    {
        $brightstar = new Brightstar();
        if (!$brightstar->fromArray($data, $columns)) {
            return null;
        }

        return $brightstar;
    }
}
