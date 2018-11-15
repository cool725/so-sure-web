<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use AppBundle\Document\Opt\EmailOptIn;
use AppBundle\Document\Opt\Opt;
use Doctrine\Common\Collections\ArrayCollection;
use FOS\UserBundle\Model\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use GeoJson\Geometry\Point;
use AppBundle\Classes\SoSure;
use AppBundle\Document\Invitation\Invitation;
use Gedmo\Mapping\Annotation as Gedmo;
use Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Scheb\TwoFactorBundle\Model\TrustedComputerInterface;
use AppBundle\Validator\Constraints\AgeValidator;
use VasilDakov\Postcode\Postcode;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\UserRepository")
 * @MongoDB\Index(keys={"identity_log.loc"="2dsphere"}, sparse="true")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class User extends BaseUser implements TwoFactorInterface, TrustedComputerInterface
{
    use ArrayToApiArrayTrait;
    use PhoneTrait;
    use GravatarTrait;
    use CurrencyTrait;

    const MAX_POLICIES_PER_USER = 2;

    const DAYS_SHOULD_DELETE_USER_WITHOUT_POLICY = 547; // 18 months
    const DAYS_SHOULD_DELETE_USER_WITH_POLICY = 2737; // 7.5 years

    const GENDER_MALE = 'male';
    const GENDER_FEMALE = 'female';
    const GENDER_UNKNOWN = 'unknown';

    const ROLE_CLAIMS = 'ROLE_CLAIMS';
    const ROLE_EMPLOYEE = 'ROLE_EMPLOYEE';
    const ROLE_CUSTOMER_SERVICES = 'ROLE_CUSTOMER_SERVICES';
    const ROLE_ADMIN = 'ROLE_ADMIN';

    const AQUISITION_COMPLETED = 'completed'; // this is for aquisitions that have already happened.
    const AQUISITION_PENDING = 'pending'; // This is for aquisitions that are on track to occur.
    const AQUISITION_POTENTIAL = 'potential'; // this is for aquired users with no policy.
    const AQUISITION_LOST = 'lost'; // this is for aquired users with a cancelled policy.

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @Assert\Email(strict=false)
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
     * @Assert\Choice({"invitation", "scode", "affiliate"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $leadSource;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $leadSourceDetails;

    /**
     * @MongoDB\EmbedOne(targetDocument="Address")
     * @Gedmo\Versioned
     */
    protected $policyAddress;

    /**
     * @MongoDB\EmbedOne(targetDocument="Address")
     * @Gedmo\Versioned
     * @var Address|null
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
     * @var ArrayCollection
     */
    protected $receivedInvitations;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $created;

    /**
     * @Assert\Length(min="0", max="50")
     * @AppAssert\Alphanumeric()
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $googleAuthenticatorSecret;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $firstName;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $lastName;

    /**
     * @Assert\Choice({"male", "female", "unknown"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $gender;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true, sparse=true)
     * @Gedmo\Versioned
     */
    protected $facebookId;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="500")
     * @MongoDB\Field(type="string")
     */
    protected $facebookAccessToken;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true, sparse=true)
     * @Gedmo\Versioned
     */
    protected $googleId;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="2200")
     * @MongoDB\Field(type="string")
     */
    protected $googleAccessToken;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true, sparse=true)
     * @Gedmo\Versioned
     */
    protected $starlingId;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="500")
     * @MongoDB\Field(type="string")
     */
    protected $starlingAccessToken;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true, sparse=true)
     * @Gedmo\Versioned
     */
    protected $token;

    /**
     * @Assert\Length(min="0", max="150")
     * @AppAssert\Token()
     * @MongoDB\Field(type="string")
     */
    protected $snsEndpoint;

    /**
     * @MongoDB\EmbedOne(targetDocument="IdentityLog")
     * @Gedmo\Versioned
     */
    protected $identityLog;

    /**
     * @MongoDB\EmbedOne(targetDocument="IdentityLog")
     */
    protected $latestMobileIdentityLog;

    /**
     * @MongoDB\EmbedOne(targetDocument="IdentityLog")
     */
    protected $latestWebIdentityLog;

    /**
     * @MongoDB\EmbedOne(targetDocument="PaymentMethod")
     * @Gedmo\Versioned
     * @var PaymentMethod
     */
    protected $paymentMethod;

    /**
     * @AppAssert\Mobile()
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $mobileNumber;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $mobileNumberVerified;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $locked = false;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $emailVerified;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Company", inversedBy="user")
     * @Gedmo\Versioned
     */
    protected $company;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AffiliateCompany", inversedBy="confirmedUsers")
     * @Gedmo\Versioned
     */
    protected $affiliate;

    /**
     * @MongoDB\ReferenceMany(targetDocument="Policy", mappedBy="user", prime={"user"})
     */
    protected $policies;

    /**
     * Secondary access to policy - not insured but allowed to access
     *
     * @MongoDB\ReferenceMany(targetDocument="Policy", mappedBy="namedUser")
     */
    protected $namedPolicies;

    /**
     * @MongoDB\ReferenceMany(targetDocument="Policy", mappedBy="payer")
     */
    protected $payerPolicies;

    /**
     * @MongoDB\ReferenceMany(targetDocument="MultiPay", mappedBy="payer", cascade={"persist"})
     */
    protected $multipays;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Opt\Opt", mappedBy="user")
     */
    protected $opts = array();

    /**
     * @MongoDB\ReferenceMany(targetDocument="Charges", mappedBy="user", cascade={"persist"})
     */
    protected $charges;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $preLaunch = false;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="1500")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $referer;

    /**
     * @MongoDB\EmbedOne(targetDocument="Attribution")
     * @Gedmo\Versioned
     */
    protected $attribution;

    /**
     * @MongoDB\EmbedOne(targetDocument="Attribution")
     * @Gedmo\Versioned
     */
    protected $latestAttribution;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $birthday;

    /**
     * @MongoDB\ReferenceOne(targetDocument="SCode")
     * @Gedmo\Versioned
     */
    protected $acceptedSCode;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $intercomId;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $digitsId;

    /**
     * @MongoDB\Field(type="hash")
     */
    protected $trusted;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $highRisk;

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $sanctionsChecks = array();

    /**
     * @MongoDB\EmbedMany(
     *  targetDocument="AppBundle\Document\SanctionsMatch"
     * )
     */
    protected $sanctionsMatches = array();

    /**
     * @MongoDB\Field(type="hash")
     */
    protected $previousPasswords = array();

    /**
     */
    protected $previousPasswordCheck;

    /**
     * @Assert\Choice({"davies", "direct-group"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $handlingTeam;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $firstLoginInApp;

    public function __construct()
    {
        parent::__construct();
        $this->charges = new \Doctrine\Common\Collections\ArrayCollection();
        $this->referrals = new \Doctrine\Common\Collections\ArrayCollection();
        $this->sentInvitations = new \Doctrine\Common\Collections\ArrayCollection();
        $this->receivedInvitations = new \Doctrine\Common\Collections\ArrayCollection();
        $this->policies = new \Doctrine\Common\Collections\ArrayCollection();
        $this->namedPolicies = new \Doctrine\Common\Collections\ArrayCollection();
        $this->multipays = new \Doctrine\Common\Collections\ArrayCollection();
        $this->created = \DateTime::createFromFormat('U', time());
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

    public function isLocked()
    {
        return $this->locked;
    }

    public function setLocked($locked)
    {
        $this->locked = $locked;
    }

    /**
     * {@inheritdoc}
     */
    public function isAccountNonLocked()
    {
        return !$this->isLocked();
    }

    /**
     * {@inheritdoc}
     */
    public function isCredentialsNonExpired()
    {
        return !$this->isPasswordChangeRequired();
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

    public function setStarlingId($starlingId)
    {
        $this->starlingId = $starlingId;
    }

    public function setStarlingAccessToken($starlingAccessToken)
    {
        $this->starlingAccessToken = $starlingAccessToken;
    }

    public function getStarlingAccessToken()
    {
        return $this->starlingAccessToken;
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

    public function getHandlingTeam()
    {
        return $this->handlingTeam;
    }

    public function setHandlingTeam($handlingTeam)
    {
        $this->handlingTeam = $handlingTeam;
    }

    public function setLeadSource($source)
    {
        $this->leadSource = $source;
    }

    public function getLeadSource()
    {
        return $this->leadSource;
    }

    public function setLeadSourceDetails($details)
    {
        $validator = new AppAssert\AlphanumericSpaceDotValidator();
        $this->leadSourceDetails = $validator->conform($details);
    }

    public function getLeadSourceDetails()
    {
        return $this->leadSourceDetails;
    }

    public function getCharges()
    {
        return $this->charges;
    }

    public function setCharges($charges)
    {
        $this->charges = $charges;
    }

    public function addCharge(Charge $charge)
    {
        $charge->setUser($this);
        $this->charges[] = $charge;
    }

    /**
     * @return Address|null
     */
    public function getBillingAddress()
    {
        return $this->billingAddress;
    }

    public function setBillingAddress(Address $billingAddress)
    {
        $this->billingAddress = $billingAddress;
    }

    public function clearBillingAddress()
    {
        $this->billingAddress = null;
    }

    public function getPolicyAddress()
    {
        return $this->policyAddress;
    }

    public function setPolicyAddress(Address $policyAddress)
    {
        $this->policyAddress = $policyAddress;
    }

    public function getCompany()
    {
        return $this->company;
    }

    public function setCompany(Company $company)
    {
        $this->company = $company;
    }

    public function getAffiliate()
    {
        return $this->affiliate;
    }

    public function setAffiliate(AffiliateCompany $affiliate)
    {
        $this->affiliate = $affiliate;
    }

    public function addPolicy(Policy $policy)
    {
        $policy->setUser($this);
        $this->policies[] = $policy;
    }

    public function getPolicies()
    {
        return $this->policies;
        /*
        $policies = [];
        foreach ($this->policies as $policy) {
            // If the user is declined we want to have the policy in the list
            // Previously ok cancelled policies can be ignored and hidden in most cases
            if (!$policy->isCancelled() || $policy->isCancelledWithUserDeclined()) {
                $policies[] = $policy;
            }
        }

        return $policies;
        */
    }

    public function getNamedPolicies()
    {
        return $this->namedPolicies;
    }

    public function getCreatedPolicies()
    {
        $policies = [];
        foreach ($this->policies as $policy) {
            if ($policy->getStatus()) {
                $policies[] = $policy;
            }
        }

        return $policies;
    }

    public function getDisplayablePoliciesSorted()
    {
        $policies = [];
        foreach ($this->policies as $policy) {
            if (in_array($policy->getStatus(), [
                Policy::STATUS_ACTIVE,
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
                Policy::STATUS_UNPAID,
                Policy::STATUS_RENEWAL,
            ])) {
                $policies[] = $policy;
            }
        }

        // sort recent to older
        usort($policies, function ($a, $b) {
            return $a->getStart() < $b->getStart();
        });

        return $policies;
    }

    public function getDisplayableCashbackSorted()
    {
        $cashback = [];
        foreach ($this->policies as $policy) {
            if ($policy->hasCashback()) {
                $cashback[] = $policy->getCashback();
            }
        }

        // sort recent to older
        usort($cashback, function ($a, $b) {
            return $a->getCreatedDate() < $b->getCreatedDate();
        });

        return $cashback;
    }

    public function getAllPolicies()
    {
        return $this->policies;
    }

    public function getPayerPolicies()
    {
        return $this->payerPolicies;
    }

    public function addPayerPolicy(Policy $policy)
    {
        $policy->setPayer($this);
        $this->payerPolicies[] = $policy;
    }

    public function addOpt(Opt $opt)
    {
        $opt->setUser($this);
        $this->opts[] = $opt;
    }

    public function getOpts()
    {
        return $this->opts;
    }

    public function getMultiPays()
    {
        return $this->multipays;
    }

    public function getActiveMultiPays()
    {
        $multiPays = [];
        foreach ($this->getMultiPays() as $multiPay) {
            if (!in_array($multiPay->getStatus(), [MultiPay::STATUS_CANCELLED, MultiPay::STATUS_REJECTED])) {
                $multiPays[] = $multiPay;
            }
        }

        return $multiPays;
    }

    public function addMultiPay(MultiPay $multipay)
    {
        // For some reason, multipay was being added twice for ::testPutPolicySCode
        // perhaps an issue with cascade persist
        // seems to have no ill effects and resolves the issue
        if ($this->multipays->contains($multipay)) {
            throw new \Exception('duplicate multipay');
        }

        $multipay->setPayer($this);
        $this->multipays[] = $multipay;
    }

    public function passwordChange($oldPassword, $oldSalt, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $this->previousPasswords[$date->format('U')] = ['password' => $oldPassword, 'salt' => $oldSalt];
    }

    public function getPreviousPasswords()
    {
        return $this->previousPasswords;
    }

    public function setPreviousPasswordCheck($previousPasswordCheck)
    {
        $this->previousPasswordCheck = $previousPasswordCheck;
    }

    public function getPreviousPasswordCheck()
    {
        return $this->previousPasswordCheck;
    }

    public function getLastPasswordChange()
    {
        $oldPasswords = $this->getPreviousPasswords();

        if (!is_array($oldPasswords)) {
            $oldPasswords = $this->getPreviousPasswords()->getValues();
        }
        if (count($oldPasswords) == 0) {
            return $this->created;
        }

        krsort($oldPasswords);
        foreach ($oldPasswords as $timestamp => $passwordData) {
            return \DateTime::createFromFormat('U', $timestamp);
        }

        return $this->created;
    }

    public function isPasswordChangeRequired(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!$this->hasEmployeeRole() && !$this->hasClaimsRole()) {
            return false;
        }

        $lastPasswordChange = $this->getLastPasswordChange();
        $diff = $date->diff($this->getLastPasswordChange());

        return $diff->days >= 90;
    }

    public function hasEmployeeRole()
    {
        return $this->hasRole(self::ROLE_EMPLOYEE) ||
            $this->hasRole(self::ROLE_ADMIN) ||
            $this->hasRole(self::ROLE_CUSTOMER_SERVICES);
    }

    public function hasClaimsRole()
    {
        return $this->hasRole(self::ROLE_CLAIMS);
    }

    public function hasCancelledPolicy()
    {
        foreach ($this->getPolicies() as $policy) {
            if ($policy->isCancelled()) {
                return true;
            }
        }

        return false;
    }

    public function hasCancelledPolicyWithUserDeclined()
    {
        foreach ($this->getAllPolicies() as $policy) {
            /** @var Policy $policy */
            if ($policy->isCancelledWithUserDeclined()) {
                return true;
            }
        }

        return false;
    }

    public function hasSuspectedFraudulentClaim()
    {
        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->hasSuspectedFraudulentClaim()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Can purchase implies that user is allowed to purchase an additional policy
     * This is different than being allowed to renew an existing policy
     */
    public function canPurchasePolicy($checkMaxPolicies = true)
    {
        if ($this->isLocked()) {
            return false;
        }

        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->hasCancelledPolicyWithUserDeclined()) {
            return false;
        }

        if ($checkMaxPolicies && count($this->getValidPolicies(true)) >= self::MAX_POLICIES_PER_USER) {
            return false;
        }

        if ($this->getAvgPolicyClaims() > 2 && !$this->areEqualToTwoDp(2, $this->getAvgPolicyClaims())) {
            return false;
        }

        // TODO only difference in purchase vs re-purchase if if the policy previously exists and there
        // is a dispossession/wreckage. As the imei check will perform that validation, its ok for now
        // although a better solution would be nice

        return true;
    }

    /**
     * Can the user re-purchase this specific policy
     * This is different than being allowed to renew an existing policy (renewal has expired)
     */
    public function canRepurchasePolicy(Policy $policy, $checkMaxPolicies = true)
    {
        if ($this->isLocked()) {
            return false;
        }

        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->hasCancelledPolicyWithUserDeclined()) {
            return false;
        }

        if ($policy->isCancelledWithPolicyDeclined()) {
            return false;
        }

        if ($checkMaxPolicies && count($this->getValidPolicies(true)) >= self::MAX_POLICIES_PER_USER) {
            return false;
        }

        if ($this->getAvgPolicyClaims() > 2 && !$this->areEqualToTwoDp(2, $this->getAvgPolicyClaims())) {
            return false;
        }

        // TODO: Consider if we want to block a different user doing a re-purchase

        // TODO only difference in purchase vs re-purchase if if the policy previously exists and there
        // is a dispossession/wreckage. As the imei check will perform that validation, its ok for now
        // although a better solution would be nice

        return true;
    }

    public function canRenewPolicy(Policy $policy)
    {
        if ($this->isLocked()) {
            return false;
        }

        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->hasCancelledPolicyWithUserDeclined()) {
            return false;
        }

        if ($policy->isCancelledWithPolicyDeclined()) {
            return false;
        }

        if ($this->getAvgPolicyClaims() > 2 && !$this->areEqualToTwoDp(2, $this->getAvgPolicyClaims())) {
            return false;
        }

        return true;
    }

    public function getAvgPolicyClaims()
    {
        $claims = 0;
        $policies = 0;
        foreach ($this->getAllPolicies() as $policy) {
            // TODO: Blend upgrades
            if ($policy->getStatus() == Policy::STATUS_CANCELLED &&
                $policy->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
                continue;
            }

            $policies++;
            $claims += count($policy->getApprovedClaims());
        }

        if ($policies > 0) {
            return $claims / $policies;
        } else {
            return 0;
        }
    }

    public function areRenewalsDesired()
    {
        // TODO: determine logic
        return true;
    }

    public function hasUnpaidPolicy()
    {
        return $this->getUnpaidPolicy() !== null;
    }

    public function getUnpaidPolicy()
    {
        foreach ($this->getPolicies() as $policy) {
            if ($policy->getStatus() == Policy::STATUS_UNPAID) {
                return $policy;
            }
        }

        return null;
    }

    public function hasActivePolicy()
    {
        foreach ($this->getPolicies() as $policy) {
            if (in_array($policy->getStatus(), [Policy::STATUS_ACTIVE])) {
                return true;
            }
        }

        return false;
    }

    public function hasValidPolicy($active = true)
    {
        foreach ($this->getPolicies() as $policy) {
            /** @var Policy $policy */
            if ($policy->isValidPolicy()) {
                if ($active) {
                    if (in_array($policy->getStatus(), [Policy::STATUS_ACTIVE])) {
                        return true;
                    }
                } else {
                    return true;
                }
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

    public function getValidPolicies($includeUnpaid = false)
    {
        $policies = [];
        foreach ($this->getPolicies() as $policy) {
            if (in_array($policy->getStatus(), [Policy::STATUS_ACTIVE])) {
                $policies[] = $policy;
            } elseif ($includeUnpaid && $policy->getStatus() == Policy::STATUS_UNPAID) {
                $policies[] = $policy;
            }
        }

        return $policies;
    }

    public function isAffiliateCandidate($days)
    {
        foreach ($this->getValidPolicies(true) as $policy) {
            /** @var Policy $policy */
            if ($policy->isPolicyOldEnough($days)) {
                return true;
            }
        }

        return false;
    }

    public function getValidPoliciesWithoutOpenedClaim($includeUnpaid = false)
    {
        $policies = [];
        foreach ($this->getPolicies() as $policy) {
            $noOpenClaim = true;
            foreach ($policy->getClaims() as $claim) {
                if (!in_array($claim->getStatus(), [
                    Claim::STATUS_APPROVED,
                    Claim::STATUS_SETTLED,
                    Claim::STATUS_DECLINED,
                    Claim::STATUS_PENDING_CLOSED,
                    Claim::STATUS_WITHDRAWN
                ])) {
                    $noOpenClaim = false;
                    break;
                }
            }
            if ($noOpenClaim) {
                if (in_array($policy->getStatus(), [Policy::STATUS_ACTIVE])) {
                    $policies[] = $policy;
                } elseif ($includeUnpaid && $policy->getStatus() == Policy::STATUS_UNPAID) {
                    $policies[] = $policy;
                }
            }
        }

        return $policies;
    }

    public function hasPartialPolicy()
    {
        return count($this->getPartialPolicies()) > 0;
    }

    public function getPartialPolicies()
    {
        $policies = [];
        foreach ($this->getPolicies() as $policy) {
            if ($policy->getStatus() === null) {
                $policies[] = $policy;
            }
        }

        // sort more recent to older
        usort($policies, function ($a, $b) {
            return $a->getCreated() < $b->getCreated();
        });

        return $policies;
    }

    public function getPendingRenewalPolicies()
    {
        $policies = [];
        foreach ($this->getPolicies() as $policy) {
            if (in_array($policy->getStatus(), [Policy::STATUS_PENDING_RENEWAL])) {
                $policies[] = $policy;
            }
        }

        return $policies;
    }

    public function getFirstPolicy()
    {
        $policies = $this->getValidPolicies(true);
        if (!is_array($policies)) {
            $policies = $policies->getValues();
        }
        if (count($policies) == 0) {
            return null;
        }

        // sort older to recent
        usort($policies, function ($a, $b) {
            return $a->getStart() > $b->getStart();
        });

        return $policies[0];
    }

    /**
     * @return Policy|null
     */
    public function getLatestPolicy()
    {
        $policies = $this->getValidPolicies(true);
        if (!is_array($policies)) {
            $policies = $policies->getValues();
        }
        if (count($policies) == 0) {
            return null;
        }

        // sort most recent to older
        usort($policies, function ($a, $b) {
            return $a->getStart() < $b->getStart();
        });

        return $policies[0];
    }

    public function hasPolicyWithSamePhone(Phone $phone)
    {
        foreach ($this->getPolicies() as $policy) {
            if ($policy instanceof PhonePolicy && $policy->getPhone()->getId() == $phone->getId()) {
                return true;
            }
        }

        return false;
    }

    public function hasPolicyCancelledAndPaymentOwed()
    {
        foreach ($this->getAllPolicies() as $policy) {
            if ($policy->isCancelledAndPaymentOwed()) {
                return true;
            }
        }

        return false;
    }

    public function hasPolicyBacsPaymentInProgress()
    {
        foreach ($this->getValidPolicies(true) as $policy) {
            /** @var Policy $policy */
            if ($policy->hasBacsPaymentInProgress()) {
                return true;
            }
        }

        return false;
    }

    public function hasBacsPaymentMethod()
    {
        return $this->getPaymentMethod() instanceof BacsPaymentMethod;
    }

    /**
     * @return BacsPaymentMethod|null
     */
    public function getBacsPaymentMethod()
    {
        if ($this->hasBacsPaymentMethod()) {
            /** @var BacsPaymentMethod $paymentMethod */
            $paymentMethod = $this->getPaymentMethod();

            return $paymentMethod;
        }

        return null;
    }

    public function hasJudoPaymentMethod()
    {
        return $this->getPaymentMethod() instanceof JudoPaymentMethod;
    }

    /**
     * @return JudoPaymentMethod|null
     */
    public function getJudoPaymentMethod()
    {
        if ($this->hasJudoPaymentMethod()) {
            /** @var JudoPaymentMethod $paymentMethod */
            $paymentMethod = $this->getPaymentMethod();

            return $paymentMethod;
        }

        return null;
    }

    public function canUpdateBacsDetails()
    {
        /** @var BacsPaymentMethod $bacsPaymentMethod */
        $bacsPaymentMethod = $this->getPaymentMethod();
        if ($bacsPaymentMethod instanceof BacsPaymentMethod &&
            $bacsPaymentMethod->getBankAccount()->isMandateInProgress()) {
                return false;
        }

        if ($this->hasPolicyBacsPaymentInProgress()) {
            return false;
        }

        return true;
    }

    public function getAnalytics()
    {
        $data = [];
        $data['os'] = null;
        $data['annualPremium'] = 0;
        $data['monthlyPremium'] = 0;
        $data['paymentsReceived'] = 0;
        $data['lastPaymentReceived'] = null;
        $data['lastConnection'] = null;
        $data['connections'] = 0;
        $data['rewardPot'] = 0;
        $data['maxPot'] = 0;
        $data['numberPolicies'] = 0;
        $data['approvedClaims'] = 0;
        $data['approvedNetworkClaims'] = 0;
        $data['accountPaidToDate'] = true;
        $data['renewalMonthlyPremiumNoPot'] = 0;
        $data['renewalMonthlyPremiumWithPot'] = 0;
        $data['paymentMethod'] = $this->getPaymentMethod() ? $this->getPaymentMethod()->getType() : null;
        $data['hasOutstandingPicSurePolicy'] = false;
        $data['connectedWithFacebook'] = mb_strlen($this->getFacebookId()) > 0;
        $data['connectedWithGoogle'] = mb_strlen($this->getGoogleId()) > 0;
        if ($this->hasBacsPaymentMethod()) {
            $data['paymentMethod'] = 'bacs';
        } elseif ($this->hasJudoPaymentMethod()) {
                $data['paymentMethod'] = 'judo';
        } else {
            $data['paymentMethod'] = 'none';
        }
        foreach ($this->getValidPolicies(true) as $policy) {
            /** @var PhonePolicy $policy */
            if (!$policy->isActive()) {
                continue;
            }
            if ($policy->getPolicyTerms()->isPicSureEnabled() && in_array($policy->getPicSureStatus(), [
                PhonePolicy::PICSURE_STATUS_INVALID,
                PhonePolicy::PICSURE_STATUS_MANUAL,
                null,
            ])) {
                $data['hasOutstandingPicSurePolicy'] = true;
            }
            $data['connections'] += count($policy->getConnections());
            $data['rewardPot'] += $policy->getPotValue();
            $data['approvedClaims'] += count($policy->getApprovedClaims());
            $data['approvedNetworkClaims'] += count($policy->getNetworkClaims(true));
            if ($phone = $policy->getPhone()) {
                if (!$data['os']) {
                    $data['os'] = $phone->getOs();
                } elseif ($data['os'] != $phone->getOs()) {
                    $data['os'] = 'Multiple';
                }
            }
            if ($plan = $policy->getPremiumPlan()) {
                $data['annualPremium'] += $policy->getPremium()->getYearlyPremiumPrice();
                $data['monthlyPremium'] += $policy->getPremium()->getMonthlyPremiumPrice();
                $data['paymentsReceived'] += count($policy->getSuccessfulPaymentCredits());

                if ($payment = $policy->getLastSuccessfulUserPaymentCredit()) {
                    if (!$data['lastPaymentReceived'] || $data['lastPaymentReceived'] < $payment->getDate()) {
                        $data['lastPaymentReceived'] = $payment->getDate();
                    }
                }
            }
            if ($connection = $policy->getLastConnection()) {
                if (!$data['lastConnection'] || $data['lastConnection'] < $connection->getDate()) {
                    $data['lastConnection'] = $connection->getDate();
                }
            }
            $data['numberPolicies']++;
            if ($policy instanceof PhonePolicy) {
                $data['devices'][] = $policy->getPhone()->__toString();
                $data['maxPot'] += $policy->getMaxPot();
            }
            if ($policy->getStatus() == Policy::STATUS_UNPAID) {
                $data['accountPaidToDate'] = false;
            }
        }
        foreach ($this->getPendingRenewalPolicies() as $policy) {
            $data['renewalMonthlyPremiumNoPot'] += $policy->getPremium()->getMonthlyPremiumPrice();
            $data['renewalMonthlyPremiumWithPot'] +=
                $policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice($policy->getPotValue());
        }
        $data['firstPolicy'] = [];
        $data['firstPolicy']['promoCode'] = null;
        $data['firstPolicy']['monthlyPremium'] = null;
        $data['firstPolicy']['minutesFinalPurchase'] = null;
        $data['firstPolicy']['minutesStartPurchase'] = null;
        if ($policy = $this->getFirstPolicy()) {
            $data['firstPolicy']['promoCode'] = $policy->getPromoCode();
            if ($premium = $policy->getPremium()) {
                $data['firstPolicy']['monthlyPremium'] = $premium->getMonthlyPremiumPrice();
            }
            if ($policy->getStart()) {
                $diff = $policy->getStart()->getTimestamp() - $this->getCreated()->getTimestamp();
                $data['firstPolicy']['minutesFinalPurchase'] = round($diff / 60);
            }
            $diff = $policy->getCreated()->getTimestamp() - $this->getCreated()->getTimestamp();
            $data['firstPolicy']['minutesStartPurchase'] = round($diff / 60);
        }
        $data['hasFullPot'] = $data['rewardPot'] > 0 && $this->areEqualToTwoDp($data['rewardPot'], $data['maxPot']);

        return $data;
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

        if (!$this->getLeadSource() && $invitation->getCreated() < $this->getCreated()) {
            $this->setLeadSource(Lead::LEAD_SOURCE_INVITATION);
            $this->setLeadSourceDetails($invitation->getInviter()->getEmail());
        }
    }

    /**
     * @return ArrayCollection
     */
    public function getReceivedInvitations()
    {
        return $this->receivedInvitations;
    }

    public function setReceivedInvitations($receivedInvitations)
    {
        $this->receivedInvitations = $receivedInvitations;
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

    public function resetFacebook()
    {
        $this->setFacebookId(null);
        $this->setFacebookAccessToken(null);
    }

    public function getFacebookId()
    {
        return $this->facebookId;
    }

    public function setGoogleId($googleId)
    {
        $this->googleId = $googleId;
    }

    public function getGoogleId()
    {
        return $this->googleId;
    }

    public function setGoogleAccessToken($googleAccessToken)
    {
        $this->googleAccessToken = $googleAccessToken;
    }

    public function getGoogleAccessToken()
    {
        return $this->googleAccessToken;
    }

    public function resetGoogle()
    {
        $this->setGoogleId(null);
        $this->setGoogleAccessToken(null);
    }

    public function getMobileNumberVerified()
    {
        return $this->mobileNumberVerified;
    }

    public function setMobileNumberVerified($mobileNumberVerified)
    {
        $this->mobileNumberVerified = $mobileNumberVerified;
    }

    public function getEmailVerified()
    {
        return $this->emailVerified;
    }

    public function setEmailVerified($emailVerified)
    {
        $this->emailVerified = $emailVerified;
    }

    public function getGoogleAuthenticatorSecret()
    {
        return $this->googleAuthenticatorSecret;
    }

    public function setGoogleAuthenticatorSecret($googleAuthenticatorSecret)
    {
        $this->googleAuthenticatorSecret = $googleAuthenticatorSecret;
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

    public function setGender($gender)
    {
        $this->gender = $gender;
    }

    public function getGender()
    {
        return $this->gender;
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

    public function getLatestWebIdentityLog()
    {
        return $this->latestWebIdentityLog;
    }

    public function setLatestWebIdentityLog($identityLog)
    {
        $this->latestWebIdentityLog = $identityLog;
    }

    public function getLatestMobileIdentityLog()
    {
        return $this->latestMobileIdentityLog;
    }

    public function setLatestMobileIdentityLog($identityLog)
    {
        $this->latestMobileIdentityLog = $identityLog;

        foreach ($this->getAllPolicies() as $policy) {
            // No need to updated cancelled or expired policies
            if (in_array($policy->getStatus(), [
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
            ])) {
                continue;
            }
            if ($policy instanceof PhonePolicy) {
                $policy->setPhoneVerified($identityLog->isSamePhone($policy->getPhone()));
            }
        }
    }

    /**
     * @return PaymentMethod
     */
    public function getPaymentMethod()
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod($paymentMethod)
    {
        $this->paymentMethod = $paymentMethod;
    }

    public function hasEmail()
    {
        return mb_strlen(trim($this->getEmail())) > 0;
    }

    public function hasPaymentMethod()
    {
        return $this->getPaymentMethod() != null;
    }

    public function hasValidPaymentMethod()
    {
        return $this->hasPaymentMethod() && $this->getPaymentMethod()->isValid();
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

    public function getAttribution()
    {
        return $this->attribution;
    }

    public function setAttribution($attribution)
    {
        if (!$this->attribution || !$this->attribution->equals($attribution)) {
            $this->attribution = $attribution;
        }
    }

    public function getLatestAttribution()
    {
        return $this->latestAttribution;
    }

    public function setLatestAttribution($latestAttribution)
    {
        if (!$this->latestAttribution || !$this->latestAttribution->equals($latestAttribution)) {
            $this->latestAttribution = $latestAttribution;
        }
    }

    public function getBirthday()
    {
        if ($this->birthday) {
            $this->birthday->setTimezone(new \DateTimeZone(SoSure::TIMEZONE));
        }

        return $this->birthday;
    }

    public function setBirthday($birthday)
    {
        $this->birthday = $birthday;
    }

    public function getAge()
    {
        if (!$this->getBirthday()) {
            return null;
        }

        $now = \DateTime::createFromFormat('U', time());
        $diff = $now->diff($this->getBirthday());

        return $diff->y;
    }

    public function getAcceptedSCode()
    {
        return $this->acceptedSCode;
    }

    public function setAcceptedSCode(SCode $scode)
    {
        $this->acceptedSCode = $scode;
        if (!$this->getLeadSource()) {
            $this->setLeadSource(Lead::LEAD_SOURCE_SCODE);
            $this->setLeadSourceDetails($scode->getCode());
        }
    }

    public function getIntercomId()
    {
        return $this->intercomId;
    }

    public function setIntercomId($intercomId)
    {
        $this->intercomId = $intercomId;
    }

    public function getDigitsId()
    {
        return $this->digitsId;
    }

    public function setDigitsId($digitsId)
    {
        $this->digitsId = $digitsId;
    }

    public function addTrustedComputer($token, \DateTime $validUntil)
    {
        $this->trusted[$token] = $validUntil->format("r");
    }

    public function isTrustedComputer($token)
    {
        if (isset($this->trusted[$token])) {
            $now = \DateTime::createFromFormat('U', time());
            $validUntil = new \DateTime($this->trusted[$token]);
            return $now < $validUntil;
        }

        return false;
    }

    public function addSanctionsCheck(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $timestamp = $date->format('U');
        $this->sanctionsChecks[] = $timestamp;
    }

    public function getSanctionsChecks()
    {
        return $this->sanctionsChecks;
    }

    public function addSanctionsMatch(SanctionsMatch $sanctionsMatch)
    {
        // only ever allow one match per sanctions record
        foreach ($this->sanctionsMatches as $match) {
            if ($match->getSanctions()->getId() == $sanctionsMatch->getSanctions()->getId()) {
                return;
            }
        }

        $this->sanctionsMatches[] = $sanctionsMatch;
    }

    public function getSanctionsMatches()
    {
        return $this->sanctionsMatches;
    }

    public function hasSoSureEmail()
    {
        return SoSure::hasSoSureEmail($this->getEmailCanonical());
    }

    public function hasSoSureRewardsEmail()
    {
        return SoSure::hasSoSureRewardsEmail($this->getEmailCanonical());
    }

    public function getImageUrl($size = 100)
    {
        if ($this->getFacebookId()) {
            return sprintf(
                'https://graph.facebook.com/%s/picture?width=%d&height=%d',
                $this->getFacebookId(),
                $size,
                $size
            );
        }

        return $this->gravatarImage($this->getEmail(), $size);
    }

    public function getImageUrlFallback($size = 100)
    {
        if ($this->getFacebookId()) {
            return sprintf(
                'https://graph.facebook.com/%s/picture?width=%d&height=%d',
                $this->getFacebookId(),
                $size,
                $size
            );
        }

        if (mb_strlen($this->getFirstName()) > 0) {
            $initial = mb_strtolower($this->getFirstName()[0]);
        } else {
            $initial = mb_strtolower($this->getEmail()[0]);
        }
        return $this->gravatarImageFallback(
            $this->getEmail(),
            $size,
            sprintf('https://cdn.so-sure.com/images/alpha/%s.png', $initial)
        );
    }

    public function setHighRisk($highRisk)
    {
        $this->highRisk = $highRisk;
    }

    public function getHighRisk()
    {
        return $this->highRisk;
    }

    public function getAdditionalPremium()
    {
        if (!$this->getHighRisk()) {
            return null;
        }

        return 500 / 12;
    }

    public function getFirstLoginInApp()
    {
        return $this->firstLoginInApp;
    }

    public function setFirstLoginInApp($firstLoginInApp)
    {
        $this->firstLoginInApp = $firstLoginInApp;
    }

    public function hasValidDetails()
    {
        // TODO: Improve validation
        if (mb_strlen($this->getFirstName()) == 0 ||
            mb_strlen($this->getLastName()) == 0 ||
            mb_strlen($this->getEmail()) == 0 ||
            mb_strlen($this->getMobileNumber()) == 0 ||
            !$this->getBirthday()) {
            return false;
        }

        if ($this->getAge() > AgeValidator::MAX_AGE || $this->getAge() < AgeValidator::MIN_AGE) {
            return false;
        }

        return true;
    }

    public function allowedMonthlyPayments()
    {
        // Billing address is required as necessary to determine postcode
        if (!$this->hasValidDetails() || !$this->getBillingAddress()) {
            return false;
        }

        if ($this->getHighRisk()) {
            return false;
        }

        $postcode = new Postcode($this->getBillingAddress()->getPostcode());

        if (in_array(mb_strtoupper($postcode->outcode()), SoSure::$yearlyOnlyPostcodeOutcodes)) {
            return false;
        } elseif (in_array($postcode->normalise(), SoSure::$yearlyOnlyPostcodes)) {
            return false;
        }

        return true;
    }

    public function allowedYearlyPayments()
    {
        // No need to require Billing address as no postcode check
        if (!$this->hasValidDetails()) {
            return false;
        }

        return true;
    }

    public function getStandardSCode()
    {
        $scode = null;
        foreach ($this->getPolicies() as $policy) {
            if ($scode = $policy->getStandardSCode()) {
                break;
            }
        }
        return $scode;
    }

    public function canDelete(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        // If the user has ever had a policy other than partial, we are unable to delete (unless after 7.5 years)
        foreach ($this->getPolicies() as $policy) {
            /** @var Policy $policy */
            if ($policy->getStatus() && $policy->getEnd()) {
                $diff = $policy->getEnd()->diff($date);
                if ($diff->invert || $diff->days <= self::DAYS_SHOULD_DELETE_USER_WITH_POLICY) {
                    return false;
                }
            }
        }

        return true;
    }

    public function getShouldDeleteDate()
    {
        $comparisionDate = $this->getCreated();
        // Any marketing optin should indicate that the user wishes to remain in the system
        // TODO: allow for user re-optin
        foreach ($this->getOpts() as $opt) {
            /** @var EmailOptIn $opt */
            if ($opt instanceof EmailOptIn && $opt->getUpdated() > $comparisionDate &&
                in_array(EmailOptIn::OPTIN_CAT_MARKETING, $opt->getCategories())) {
                $comparisionDate = $opt->getUpdated();
            }
        }

        $deleteDate = clone $comparisionDate;
        $deleteDate = $deleteDate->add(new \DateInterval(
            sprintf('P%dD', self::DAYS_SHOULD_DELETE_USER_WITHOUT_POLICY)
        ));

        return $deleteDate;
    }

    public function shouldDelete(\DateTime $date = null)
    {
        if ($this->hasClaimsRole() || $this->hasEmployeeRole() ||
            $this->hasSoSureEmail() || $this->hasSoSureRewardsEmail()) {
            return false;
        }

        if (!$this->canDelete($date)) {
            return false;
        }

        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        return $date >= $this->getShouldDeleteDate();
    }

    public function shouldNotifyDelete(\DateTime $date = null)
    {
        if ($this->hasClaimsRole() || $this->hasEmployeeRole()) {
            return false;
        }

        if (!$this->canDelete($date)) {
            return false;
        }

        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $diff = $date->diff($this->getShouldDeleteDate());

        return $diff->invert && $diff->days == 21;
    }

    public function toApiArray($intercomHash = null, $identityId = null, $token = null, $debug = false)
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
            'google_id' => $this->getGoogleId(),
            'cognito_token' => [ 'id' => $identityId, 'token' => $token ],
            'user_token' => ['token' => $this->getToken()],
            'addresses' => $addresses,
            'mobile_number' => $this->getMobileNumber(),
            'policies' => $this->eachApiArray($this->getPolicies()),
            'received_invitations' => $this->eachApiArray($this->getUnprocessedReceivedInvitations(), true, $debug),
            'has_cancelled_policy' => $this->hasCancelledPolicyWithUserDeclined(),
            'has_unpaid_policy' => $this->hasUnpaidPolicy(),
            'has_valid_policy' => $this->hasActivePolicy(), // poor initial naming :(
            'birthday' => $this->getBirthday() ? $this->getBirthday()->format(\DateTime::ATOM) : null,
            'image_url' => $this->getImageUrl(),
            'sns_endpoint' => $this->getSnsEndpoint() ? $this->getSnsEndpoint() : null,
            'intercom_token' => $intercomHash,
            'multipay_policies' => $this->eachApiArray($this->getActiveMultiPays()),
            'facebook_filters' => $this->eachApiMethod(
                $this->getUnprocessedReceivedInvitations(),
                'getInviterFacebookId',
                false
            ),
            'can_purchase_policy' => $this->canPurchasePolicy(),
            'has_payment_method' => $this->hasValidPaymentMethod(),
            'card_details' => $this->getPaymentMethod() && $this->getPaymentMethod()->getType() == 'judo' ?
                $this->getPaymentMethod()->__toString() :
                'Please update your card',
            'payment_method' => $this->getPaymentMethod() ?
                $this->getPaymentMethod()->getType() :
                null,
            'has_mobile_number_verified' => $this->getMobileNumberVerified()
        ];
    }

    /**
     * Tells you the what state the user is in regarding affiliate aquisition.
     * @return string aquisition state name.
     */
    public function aquisitionStatus()
    {
        if ($this->affiliate) {
            return static::AQUISITION_COMPLETED;
        } elseif ($this->hasActivePolicy() || $this->hasUnpaidPolicy()) {
            return static::AQUISITION_PENDING;
        } elseif ($this->hasPolicy()) {
            return static::AQUISITION_LOST;
        } else {
            return static::AQUISITION_POTENTIAL;
        }
    }
}
