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

    /** @MongoDB\Field(type="string") */
    protected $detail;

    /** @MongoDB\Field(type="float") */
    protected $policyPrice;

    public function __construct()
    {
    }

    public function init($make = null, $model = null, $detail = null, $policyPrice = null)
    {
        $this->make = $make;
        $this->model = $model;
        $this->detail = $detail;
        $this->policyPrice = $policyPrice;
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

    public function getDetail()
    {
        return $this->detail;
    }

    public function getPolicyPrice()
    {
        return $this->policyPrice;
    }

    public function __toString()
    {
        return sprintf("%s %s (%s)", $this->make, $this->model, $this->detail);
    }
}
