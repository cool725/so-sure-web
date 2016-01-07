<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class User extends BaseUser
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\String(name="name", nullable=true) */
    protected $name;

    /** @MongoDB\String(name="facebook_id", nullable=true) */
    protected $facebook_id;

    /** @MongoDB\String(name="facebook_access_token", nullable=true) */
    protected $facebook_access_token;
    
    public function __construct()
    {
        parent::__construct();
        $this->created = new \DateTime();
        // your own logic
    }

    public function getId()
    {
        return $this->id;
    }

    public function setFacebookId($facebook_id)
    {
        $this->facebook_id = $facebook_id;
    }

    public function setFacebookAccessToken($facebook_access_token)
    {
        $this->facebook_access_token = $facebook_access_token;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }
}
