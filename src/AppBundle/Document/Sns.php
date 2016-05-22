<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class Sns
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\Field(type="string") @MongoDB\Index(unique=true) */
    protected $endpoint;

    /** @MongoDB\Field(type="string") */
    protected $all;

    /** @MongoDB\Field(type="string") */
    protected $unregistered;

    /** @MongoDB\Field(type="string") */
    protected $registered;

    /** @MongoDB\Field(type="collection") */
    protected $others = array();

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }
    
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function setEndpoint($endpoint)
    {
        $this->endpoint = $endpoint;
    }
    
    public function getAll()
    {
        return $this->all;
    }

    public function setAll($all)
    {
        $this->all = $all;
    }

    public function getUnregistered()
    {
        return $this->unregistered;
    }

    public function setUnregistered($unregistered)
    {
        $this->unregistered = $unregistered;
    }

    public function getRegistered()
    {
        return $this->registered;
    }

    public function setRegistered($registered)
    {
        $this->registered = $registered;
    }

    public function getOthers()
    {
        return $this->others;
    }

    public function addOthers($key, $value)
    {
        $this->others[$key] = $value;
    }
}
