<?php
// src/AppBundle/Document/User.php

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

    public function getModel()
    {
        return $this->model;
    }

    public function getDevices()
    {
        return $this->devices;
    }

    public function getPolicyPrice()
    {
        return $this->policyPrice;
    }

    public function getLossPrice()
    {
        return $this->lossPrice;
    }

    public function getMemory()
    {
        return $this->memory;
    }
    
    public function __toString()
    {
        $name = sprintf("%s %s", $this->make, $this->model);
        if ($this->memory) {
            $name = sprintf("%s (%s)", $name, $this->memory);
        }

        return $name;
    }
}
