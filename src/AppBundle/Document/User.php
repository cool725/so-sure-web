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
     * @MongoDB\Id
     */
    protected $id;

    protected $referralId;

    /**
     * @MongoDB\ReferenceMany(targetDocument="User", mappedBy="referred")
     */
    protected $referrals;
    
    /**
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="referrals")
     */
    protected $referred;

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
        $this->referrals = new \Doctrine\Common\Collections\ArrayCollection();
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setFacebookId($facebook_id)
    {
        $this->facebook_id = $facebook_id;
    }

    public function getReferralId()
    {
        return $this->referralId;
    }

    public function setReferralId($referralId)
    {
        $this->referralId = $referralId;
    }

    public function getReferred()
    {
        return $this->referred;
    }

    public function setReferred($referred)
    {
        $this->referred = $referred;
    }

    public function addReferral(User $referred)
    {
        $referred->setReferred($this);
        $this->referrals[] = $referred;
    }

    public function getReferrals()
    {
        return $this->referrals;
    }

    public function setFacebookAccessToken($facebook_access_token)
    {
        $this->facebook_access_token = $facebook_access_token;
    }

    public function getFacebookAccessToken()
    {
        return $this->facebook_access_token;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function setEmail($email){
        $this->email = $email;
        $this->username = $email;
    }

    public function setEmailCanonical($emailCanonical){
        $this->emailCanonical = $emailCanonical;
        $this->usernameCanonical = $emailCanonical;
    }
}
