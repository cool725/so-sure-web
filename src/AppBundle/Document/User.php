<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use GeoJson\Geometry\Point;
use AppBundle\Document\Invitation\Invitation;

/**
 * @MongoDB\Document
 * @MongoDB\Index(keys={"signup_loc"="2dsphere"}, sparse="true")
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

    /**
     * @MongoDB\ReferenceMany(targetDocument="Address", mappedBy="user")
     */
    protected $addresses;

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\Invitation\Invitation",
     *  name="sent_invitations",
     *  mappedBy="inviter")
     */
    protected $sentInvitations;

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\Invitation\Invitation",
     *  name="received_invitations",
     *  mappedBy="invitee")
     */
    protected $receivedInvitations;

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\String(name="first_name", nullable=true) */
    protected $firstName;

    /** @MongoDB\String(name="last_name", nullable=true) */
    protected $lastName;

    /** @MongoDB\String(name="facebook_id", nullable=true) */
    protected $facebookId;

    /** @MongoDB\String(name="facebook_access_token", nullable=true) */
    protected $facebookAccessToken;

    /** @MongoDB\String(name="token", nullable=true) @MongoDB\Index(unique=true, sparse=true) */
    protected $token;

    /** @MongoDB\String(name="sns_endpoint", nullable=true) */
    protected $snsEndpoint;

    /** @MongoDB\String(name="cognito_id", nullable=true) */
    protected $cognitoId;

    /** @MongoDB\String(name="signup_ip", nullable=true) */
    protected $signupIp;

    /** @MongoDB\String(name="signup_country", nullable=true) */
    protected $signupCountry;

    /** @MongoDB\EmbedOne(targetDocument="Coordinates", name="signup_loc") */
    protected $signupLoc;

    /** @MongoDB\Distance */
    public $signupDistance;

    /** @MongoDB\EmbedOne(targetDocument="Gocardless", name="gocardless") */
    protected $gocardless;

    /** @MongoDB\String(name="mobile_number", nullable=true) */
    protected $mobileNumber;

    /** @MongoDB\ReferenceMany(targetDocument="Policy", mappedBy="user") */
    protected $policies;

    public function __construct()
    {
        parent::__construct();
        $this->referrals = new \Doctrine\Common\Collections\ArrayCollection();
        $this->addresses = new \Doctrine\Common\Collections\ArrayCollection();
        $this->sentInvitations = new \Doctrine\Common\Collections\ArrayCollection();
        $this->receivedInvitations = new \Doctrine\Common\Collections\ArrayCollection();
        $this->policies = new \Doctrine\Common\Collections\ArrayCollection();
        $this->created = new \DateTime();
        $this->token = bin2hex(openssl_random_pseudo_bytes(64));
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getCognitoId()
    {
        return $this->cognitoId;
    }

    public function setCognitoId($cognitoId)
    {
        $this->cognitoId = $cognitoId;
    }

    public function setFacebookId($facebookId)
    {
        $this->facebookId = $facebookId;
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

    public function addAddress(Address $address)
    {
        $address->setUser($this);
        $this->addresses[] = $address;
    }

    public function getAddresses()
    {
        return $this->addresses;
    }

    public function getBillingAddress()
    {
        foreach ($this->addresses as $address) {
            if ($address->getType() == Address::TYPE_BILLING) {
                return $address;
            }
        }

        return null;
    }

    public function addPolicy(Policy $policy)
    {
        $policy->setUser($this);
        $this->policies[] = $policy;
    }

    public function getPolicies()
    {
        return $this->policies;
    }

    public function addSentInvitation(Invitation $invitation)
    {
        $invitation->setInviter($this);
        $this->sentInvitations[] = $invitation;
    }

    public function getSentInvitations()
    {
        return $this->sentInvitations;
    }

    public function addReceivedInvitation(Invitation $invitation)
    {
        $invitation->setInvitee($this);
        $this->receivedInvitations[] = $invitation;
    }

    public function getReceivedInvitations()
    {
        return $this->receivedInvitations;
    }

    public function setFacebookAccessToken($facebookAccessToken)
    {
        $this->facebookAccessToken = $facebookAccessToken;
    }

    public function getFacebookAccessToken()
    {
        return $this->facebookAccessToken;
    }

    public function getFacebookId()
    {
        return $this->facebookId;
    }

    public function getFirstName()
    {
        return $this->firstName;
    }

    public function setFirstName($firstName)
    {
        $this->firstName = $firstName;
    }

    public function getLastName()
    {
        return $this->lastName;
    }

    public function setLastName($lastName)
    {
        $this->lastName = $lastName;
    }

    public function getName()
    {
        return sprintf("%s %s", $this->getFirstName(), $this->getLastName());
    }

    public function setEmail($email)
    {
        $this->email = $email;
        $this->username = $email;
    }

    public function setEmailCanonical($emailCanonical)
    {
        $this->emailCanonical = $emailCanonical;
        $this->usernameCanonical = $emailCanonical;
    }

    public function getToken()
    {
        return $this->token;
    }

    public function getSnsEndpoint()
    {
        return $this->snsEndpoint;
    }

    public function setSnsEndpoint($snsEndpoint)
    {
        $this->snsEndpoint = $snsEndpoint;
    }

    public function getSignupIp()
    {
        return $this->signupIp;
    }

    public function setSignupIp($signupIp)
    {
        $this->signupIp = $signupIp;
    }

    public function getSignupCountry()
    {
        return $this->signupCountry;
    }

    public function setSignupCountry($signupCountry)
    {
        $this->signupCountry = $signupCountry;
    }

    public function getSignupLoc()
    {
        return $this->signupLoc;
    }

    public function setSignupLoc($signupLoc)
    {
        $this->signupLoc = $signupLoc;
    }

    public function getGocardless()
    {
        return $this->gocardless;
    }

    public function setGocardless($gocardless)
    {
        $this->gocardless = $gocardless;
    }

    public function hasGocardless()
    {
        return $this->getGocardless() !== null;
    }

    public function hasValidGocardlessDetails()
    {
        if (!$this->getFirstName() || !$this->getLastName()) {
            return false;
        }

        $billing = $this->getBillingAddress();
        if (!$billing || !$billing->getLine1() || !$billing->getPostcode() || !$billing->getCity()) {
            return false;
        }

        return true;
    }

    public function getMobileNumber()
    {
        return $this->mobileNumber;
    }

    public function setMobileNumber($mobileNumber)
    {
        $this->mobileNumber = $mobileNumber;
    }

    public function toApiArray($identityId = null, $token = null)
    {
        $addresses = [];
        if ($this->addresses) {
            foreach ($this->addresses as $address) {
                $addresses[] = $address->toApiArray();
            }
        }
        $policies = [];
        if ($this->policies) {
            foreach ($this->policies as $policy) {
                $policies[] = $policy->getId();
            }
        }

        return [
          'id' => $this->getId(),
          'email' => $this->getEmailCanonical(),
          'first_name' => $this->getFirstName(),
          'last_name' => $this->getLastName(),
          'facebook_id' => $this->getFacebookId(),
          'cognito_token' => [ 'id' => $identityId, 'token' => $token ],
          'user_token' => ['token' => $this->getToken()],
          'addresses' => $addresses,
          'mobile_number' => $this->getMobileNumber(),
          'policies' => $policies,
        ];
    }
}
