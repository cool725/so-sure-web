<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 */
class Sns
{
    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $created;

    /**
     * @Assert\Length(min="35", max="150")
     * @AppAssert\Token()
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true)
     */
    protected $endpoint;

    /**
     * @Assert\Length(min="35", max="150")
     * @AppAssert\Token()
     * @MongoDB\Field(type="string")
     */
    protected $all;

    /**
     * @Assert\Length(min="35", max="150")
     * @AppAssert\Token()
     * @MongoDB\Field(type="string")
     */
    protected $unregistered;

    /**
     * @Assert\Length(min="35", max="150")
     * @AppAssert\Token()
     * @MongoDB\Field(type="string")
     */
    protected $registered;

    /** @MongoDB\Field(type="collection") */
    protected $others = array();

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
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
