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

    /** @MongoDB\String(name="make", nullable=false) */
    protected $make;

    /** @MongoDB\String(name="model", nullable=false) */
    protected $model;
    
    public function __construct()
    {
        parent::__construct();
        // your own logic
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
}
