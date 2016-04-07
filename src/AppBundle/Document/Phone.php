<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class Phone
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /** @MongoDB\Field(type="string") */
    protected $make;

    /** @MongoDB\Field(type="string") */
    protected $model;

    /** @MongoDB\Field(type="collection") @MongoDB\Index(unique=false) */
    protected $devices;

    /** @MongoDB\Field(type="float", nullable=true) */
    protected $memory;

    /** @MongoDB\Field(type="float") */
    protected $policyPrice;

    /** @MongoDB\Field(type="float") */
    protected $lossPrice;

    public function __construct()
    {
    }

    public function init(
        $make,
        $model,
        $policyPrice,
        $lossPrice,
        $memory = null,
        $devices = null
    ) {
        $this->make = $make;
        $this->model = $model;
        $this->devices = $devices;
        $this->memory = $memory;
        $this->policyPrice = $policyPrice;
        $this->lossPrice = $lossPrice;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getMake()
    {
        return $this->make;
    }

    public function setMake($make)
    {
        $this->make = $make;
    }

    public function getModel()
    {
        return $this->model;
    }

    public function setModel($model)
    {
        $this->model = $model;
    }

    public function getDevices()
    {
        return $this->devices;
    }

    public function setDevices($devices)
    {
        $this->devices = $devices;
    }

    public function getPolicyPrice()
    {
        return $this->toTwoDp($this->policyPrice);
    }

    public function setPolicyPrice($policyPrice)
    {
        $this->policyPrice = $policyPrice;
    }

    public function getYearlyPolicyPrice()
    {
        return $this->toTwoDp($this->policyPrice * 12);
    }

    public function getLossPrice()
    {
        return $this->toTwoDp($this->lossPrice);
    }

    public function setLossPrice($lossPrice)
    {
        $this->lossPrice = $lossPrice;
    }

    public function getYearlyLossPrice()
    {
        return $this->toTwoDp($this->lossPrice * 12);
    }

    public function getTotalPrice()
    {
        return $this->getPolicyPrice() + $this->getLossPrice();
    }

    public function getYearlyTotalPrice()
    {
        return $this->toTwoDp($this->getTotalPrice() * 12);
    }

    public function getMemory()
    {
        return $this->memory;
    }

    public function setMemory($memory)
    {
        $this->memory = $memory;
    }

    public function getMaxPot()
    {
        return $this->toTwoDp($this->getYearlyTotalPrice() * 0.8);
    }

    public function getConnectionValue()
    {
        return 10;
    }

    public function getMaxConnections()
    {
        return (int) ceil($this->getMaxPot() / $this->getConnectionValue());
    }

    private function toTwoDp($float)
    {
        return round($float, 2);
    }
    
    public function __toString()
    {
        $name = sprintf("%s %s", $this->make, $this->model);
        if ($this->memory) {
            $name = sprintf("%s (%s GB)", $name, $this->memory);
        }

        return $name;
    }

    public function toApiArray()
    {
        return [
            'make' => $this->getMake(),
            'model' => $this->getModel(),
            'devices' => $this->getDevices(),
            'memory' => $this->getMemory(),
        ];
    }

    public function toEditArray()
    {
        return [
            'make' => $this->getMake(),
            'model' => $this->getModel(),
            'devices' => $this->getDevices(),
            'memory' => $this->getMemory(),
            'policy_price' => $this->getPolicyPrice(),
            'loss_price' => $this->getLossPrice(),
        ];
    }
}
