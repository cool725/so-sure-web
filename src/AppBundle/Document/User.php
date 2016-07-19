<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use GeoJson\Geometry\Point;
use AppBundle\Document\Invitation\Invitation;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\UserRepository")
 * @MongoDB\Index(keys={"identity_log.loc"="2dsphere"}, sparse="true")
 * @Gedmo\Loggable
 *
 */
class User extends BaseUser
{
    use ArrayToApiArrayTrait;
    use PhoneTrait;

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\Email(strict=true)
     * @Gedmo\Versioned
     */
    protected $email;

    protected $referralId;

    /**
     * @MongoDB\ReferenceMany(targetDocument="User", mappedBy="referred")
     */
    protected $referrals;
    
    /**
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="referrals")
     * @Gedmo\Versioned
     */
    protected $referred;

    /**
     * @MongoDB\EmbedOne(targetDocument="Address")
     * @Gedmo\Versioned
     */
    protected $policyAddress;

    /**
     * @MongoDB\EmbedOne(targetDocument="Address")
     * @Gedmo\Versioned
     */
    protected $billingAddress;

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\Invitation\Invitation",
     *  mappedBy="inviter")
     */
    protected $sentInvitations;

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\Invitation\Invitation",
     *  mappedBy="invitee")
     */
    protected $receivedInvitations;

    /** @MongoDB\Date() */
    protected $created;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $firstName;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $lastName;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true, sparse=true)
     * @Gedmo\Versioned
     */
    protected $facebookId;

    /** @MongoDB\Field(type="string") */
    protected $facebookAccessToken;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true, sparse=true)
     * @Gedmo\Versioned
     */
    protected $token;

    /** @MongoDB\Field(type="string") */
    protected $snsEndpoint;

    /**
     * @MongoDB\EmbedOne(targetDocument="IdentityLog")
     * @Gedmo\Versioned
     */
    protected $identityLog;

    /**
     * @MongoDB\EmbedOne(targetDocument="PaymentMethod")
     * @Gedmo\Versioned
     */
    protected $paymentMethod;

    /**
     * @Assert\Regex(pattern="/^(00447[1-9]\d{8,8}|\+447[1-9]\d{8,8})$/")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $mobileNumber;

    /**
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $mobileVerified;

    /**
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $emailVerified;

    /**
     * @MongoDB\ReferenceMany(targetDocument="Policy", mappedBy="user")
     */
    protected $policies;

    /**
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $preLaunch = false;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $referer;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $birthday;

    /**
     * @MongoDB\ReferenceOne(targetDocument="SCode")
     * @Gedmo\Versioned
     */
    protected $acceptedSCode;

    public function __construct()
    {
        parent::__construct();
        $this->referrals = new \Doctrine\Common\Collections\ArrayCollection();
        $this->sentInvitations = new \Doctrine\Common\Collections\ArrayCollection();
        $this->receivedInvitations = new \Doctrine\Common\Collections\ArrayCollection();
        $this->policies = new \Doctrine\Common\Collections\ArrayCollection();
        $this->created = new \DateTime();
        $this->resetToken();
    }

    public function resetToken()
    {
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

    public function getCreated()
    {
        return $this->created;
    }

    public function setCreated($created)
    {
        $this->created = $created;
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

    public function getBillingAddress()
    {
        return $this->billingAddress;
    }

    public function setBillingAddress(Address $billingAddress)
    {
        $this->billingAddress = $billingAddress;
    }

    public function getPolicyAddress()
    {
        return $this->policyAddress;
    }

    public function setPolicyAddress(Address $policyAddress)
    {
        $this->policyAddress = $policyAddress;
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

    public function hasCancelledPolicy()
    {
        foreach ($this->getPolicies() as $policy) {
            if ($policy->getStatus() == Policy::STATUS_CANCELLED) {
                return true;
            }
        }

        return false;
    }

    public function hasUnpaidPolicy()
    {
        foreach ($this->getPolicies() as $policy) {
            if ($policy->getStatus() == Policy::STATUS_UNPAID) {
                return true;
            }
        }

        return false;
    }

    public function hasValidPolicy()
    {
        foreach ($this->getPolicies() as $policy) {
            if (in_array($policy->getStatus(), [Policy::STATUS_ACTIVE, Policy::STATUS_PENDING])) {
                return true;
            }
        }

        return false;
    }

    public function hasPolicy()
    {
        foreach ($this->getPolicies() as $policy) {
            if ($policy->getStatus() != null) {
                return true;
            }
        }

        return false;
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

    public function getReceivedInvitationsAsArray()
    {
        // TODO: should be instanceof \Doctrine\Common\Collections\ArrayCollection, but not working
        if (is_object($this->getReceivedInvitations())) {
            return $this->getReceivedInvitations()->toArray();
        }

        return $this->getReceivedInvitations();
    }

    public function getUnprocessedReceivedInvitations()
    {
        return array_filter($this->getReceivedInvitationsAsArray(), function ($invitation) {
            return !$invitation->isProcessed();
        });
    }

    public function hasReceivedInvitations()
    {
        // TODO: Necessary? Some indications say that you need to interate in order to load
        foreach ($this->receivedInvitations as $invitation) {
            return true;
        }

        return false;
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

    public function getMobileVerified()
    {
        return $this->mobileVerified;
    }

    public function setMobileVerified($mobileVerified)
    {
        $this->mobileVerified = $mobileVerified;
    }

    public function getEmailVerified()
    {
        return $this->emailVerified;
    }

    public function setEmailVerified($emailVerified)
    {
        $this->emailVerified = $emailVerified;
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

    public function getIdentityLog()
    {
        return $this->identityLog;
    }

    public function setIdentityLog($identityLog)
    {
        $this->identityLog = $identityLog;
    }

    public function getPaymentMethod()
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod($paymentMethod)
    {
        $this->paymentMethod = $paymentMethod;
    }

    public function hasPaymentMethod()
    {
        return $this->getPaymentMethod() !== null;
    }

    public function hasValidBillingDetails()
    {
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

    public function setMobileNumber($mobile)
    {
        $this->mobileNumber = $this->normalizeUkMobile($mobile);
    }

    public function getReferer()
    {
        return $this->referer;
    }

    public function setReferer($referer)
    {
        $this->referer = $referer;
    }

    public function isPreLaunch()
    {
        return $this->preLaunch;
    }

    public function setPreLaunch($preLaunch)
    {
        $this->preLaunch = $preLaunch;
    }

    public function getBirthday()
    {
        return $this->birthday;
    }

    public function setBirthday($birthday)
    {
        $this->birthday = $birthday;
    }

    public function getAcceptedSCode()
    {
        return $this->acceptedSCode;
    }

    public function setAcceptedSCode(SCode $scode)
    {
        $this->acceptedSCode = $scode;
    }

    public function hasSoSureEmail()
    {
        return stripos($this->getEmailCanonical(), '@so-sure.com') !== false;
    }

    public function hasValidDetails()
    {
        // TODO: Improve validation
        if (strlen($this->getFirstName()) == 0 ||
            strlen($this->getLastName()) == 0 ||
            strlen($this->getEmail()) == 0 ||
            strlen($this->getMobileNumber()) == 0 ||
            !$this->getBirthday()) {
            return false;
        }

        $now = new \DateTime();
        $diff = $now->diff($this->getBirthday());
        if ($diff->y > 150 || $diff->y < 18) {
            return false;
        }

        return true;
    }

    public function toApiArray($identityId = null, $token = null, $debug = false)
    {
        $addresses = [];
        if ($this->getBillingAddress()) {
            $addresses[] = $this->getBillingAddress()->toApiArray();
        }
        if ($this->getPolicyAddress()) {
            $addresses[] = $this->getPolicyAddress()->toApiArray();
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
          'policies' => $this->eachApiArray($this->policies),
          'received_invitations' => $this->eachApiArray($this->getUnprocessedReceivedInvitations(), $debug),
          'has_cancelled_policy' => $this->hasCancelledPolicy(),
          'has_unpaid_policy' => $this->hasUnpaidPolicy(),
          'has_valid_policy' => $this->hasValidPolicy(),
          'birthday' => $this->getBirthday() ? $this->getBirthday()->format(\DateTime::ATOM) : null
        ];
    }
}
