<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Doctrine\ODM\MongoDB\PersistentCollection;
use Gedmo\Mapping\Annotation as Gedmo;
use AppBundle\Classes\Salva;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("policy_type")
 * @MongoDB\DiscriminatorMap({"phone"="PhonePolicy"})
 * @Gedmo\Loggable
 */
abstract class Policy
{
    use ArrayToApiArrayTrait;
    use CurrencyTrait;

    const RISK_LEVEL_HIGH = 'high';
    const RISK_LEVEL_MEDIUM = 'medium';
    const RISK_LEVEL_LOW = 'low';

    const RISK_CONNECTED_POT_ZERO = 'connected pot £0';
    const RISK_CONNECTED_SELF_CLAIM = 'claim on policy';
    const RISK_CONNECTED_RECENT_NETWORK_CLAIM = 'connected w/recent claim';
    const RISK_CONNECTED_ESTABLISHED_NETWORK_CLAIM = 'connected w/established claim (>30 days)';
    const RISK_CONNECTED_NO_CLAIM = 'connected w/no claim';
    const RISK_NOT_CONNECTED_NEW_POLICY = 'not connected w/new policy';
    const RISK_NOT_CONNECTED_ESTABLISHED_POLICY = 'not connected w/established polish (>30 days)';

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_UNPAID = 'unpaid';

    const CANCELLED_UNPAID = 'unpaid';
    const CANCELLED_FRAUD = 'fraud';
    const CANCELLED_GOODWILL = 'goodwill';
    const CANCELLED_COOLOFF = 'cooloff';
    const CANCELLED_BADRISK = 'badrisk';

    // First 1000 policies
    const PROMO_LAUNCH = 'launch';

    const PAYMENT_DD_MONTHLY = 'gocardless_monthly';

    public static $riskLevels = [
        self::RISK_CONNECTED_POT_ZERO => self::RISK_LEVEL_HIGH,
        self::RISK_CONNECTED_SELF_CLAIM => self::RISK_LEVEL_HIGH,
        self::RISK_CONNECTED_RECENT_NETWORK_CLAIM => self::RISK_LEVEL_MEDIUM,
        self::RISK_CONNECTED_ESTABLISHED_NETWORK_CLAIM => self::RISK_LEVEL_LOW,
        self::RISK_CONNECTED_NO_CLAIM => self::RISK_LEVEL_LOW,
        self::RISK_NOT_CONNECTED_NEW_POLICY => self::RISK_LEVEL_HIGH,
        self::RISK_NOT_CONNECTED_ESTABLISHED_POLICY => self::RISK_LEVEL_MEDIUM,
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceMany(targetDocument="Payment", mappedBy="policy", cascade={"persist"})
     */
    protected $payments;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="policies")
     * @Gedmo\Versioned
     */
    protected $user;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $cancelledReason;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true, sparse=true)
     * @Gedmo\Versioned
     */
    protected $policyNumber;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $paymentType;

    /**
     * @MongoDB\Field(type="string")
     */
    protected $gocardlessMandate;

    /**
     * @MongoDB\Field(type="string")
     */
    protected $gocardlessSubscription;

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\Invitation\Invitation",
     *  mappedBy="policy",
     *  cascade={"persist"}
     * )
     */
    protected $invitations;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\PolicyTerms")
     * @Gedmo\Versioned
     */
    protected $policyTerms;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\PolicyKeyFacts")
     * @Gedmo\Versioned
     */
    protected $policyKeyFacts;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Connection", cascade={"persist"})
     */
    protected $connections = array();

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Claim", cascade={"persist"})
     */
    protected $claims = array();

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $start;

    /**
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $end;

    /**
     * @MongoDB\Field(type="float", nullable=false)
     * @Gedmo\Versioned
     */
    protected $potValue;

    /**
     * @MongoDB\Field(type="float", nullable=false)
     * @Gedmo\Versioned
     */
    protected $historicalMaxPotValue;

    /**
     * @MongoDB\Field(type="float", nullable=false)
     * @Gedmo\Versioned
     */
    protected $promoPotValue;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $promoCode;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Premium")
     * @Gedmo\Versioned
     */
    protected $premium;

    /**
     * @MongoDB\EmbedOne(targetDocument="IdentityLog")
     * @Gedmo\Versioned
     */
    protected $identityLog;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\ScheduledPayment", cascade={"persist"})
     */
    protected $scheduledPayments = array();

    /** @MongoDB\Field(type="hash") */
    protected $salvaPolicyNumbers = array();

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->payments = new \Doctrine\Common\Collections\ArrayCollection();
        $this->invitations = new \Doctrine\Common\Collections\ArrayCollection();
        $this->potValue = 0;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getPayments()
    {
        return $this->payments;
    }

    public function addPayment($payment)
    {
        $payment->setPolicy($this);
        $payment->calculateIpt();
        $this->payments->add($payment);
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        if (!$user->getBillingAddress()) {
            throw new \Exception('User must have a billing address');
        }

        $this->user = $user;
    }

    public function getStart()
    {
        return $this->start;
    }

    public function setStart(\DateTime $start)
    {
        $this->start = $start;
    }

    public function getEnd()
    {
        return $this->end;
    }

    public function setEnd(\DateTime $end)
    {
        $this->end = $end;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getCancelledReason()
    {
        return $this->cancelledReason;
    }

    public function setCancelledReason($cancelledReason)
    {
        $this->cancelledReason = $cancelledReason;
    }

    public function getPolicyNumber()
    {
        return $this->policyNumber;
    }

    public function setPolicyNumber($policyNumber)
    {
        $this->policyNumber = $policyNumber;
    }

    public function getPaymentType()
    {
        return $this->paymentType;
    }

    public function setPaymentType($paymentType)
    {
        $this->paymentType = $paymentType;
    }

    public function getGocardlessMandate()
    {
        return $this->gocardlessMandate;
    }

    public function setGocardlessMandate($gocardlessMandate)
    {
        $this->gocardlessMandate = $gocardlessMandate;
    }

    public function getGocardlessSubscription()
    {
        return $this->gocardlessSubscription;
    }

    public function setGocardlessSubscription($gocardlessSubscription)
    {
        $this->gocardlessSubscription = $gocardlessSubscription;
    }

    public function addConnection(Connection $connection)
    {
        $connection->setSourcePolicy($this);
        $this->connections[] = $connection;
    }

    public function getConnections()
    {
        return $this->connections;
    }

    public function addClaim(Claim $claim)
    {
        $claim->setPolicy($this);
        $this->claims[] = $claim;
    }

    public function getClaims()
    {
        return $this->claims;
    }

    public function getPotValue()
    {
        return $this->toTwoDp($this->potValue);
    }

    public function setPotValue($potValue)
    {
        if ($this->toTwoDp($potValue) > $this->getMaxPot()) {
            throw new \Exception(sprintf('Max pot value exceeded (%s of %s)', $potValue, $this->getMaxPot()));
        }

        $this->potValue = $potValue;

        if (!$this->getHistoricalMaxPotValue() || $potValue > $this->getHistoricalMaxPotValue()) {
            $this->historicalMaxPotValue = $potValue;
        }
    }

    public function getHistoricalMaxPotValue()
    {
        return $this->historicalMaxPotValue;
    }

    public function getPromoPotValue()
    {
        return $this->toTwoDp($this->promoPotValue);
    }

    public function setPromoPotValue($promoPotValue)
    {
        $this->promoPotValue = $promoPotValue;
    }

    public function getPolicyKeyFacts()
    {
        return $this->policyKeyFacts;
    }

    public function setPolicyKeyFacts(PolicyKeyFacts $policyKeyFacts)
    {
        $this->policyKeyFacts = $policyKeyFacts;
    }

    public function getPolicyTerms()
    {
        return $this->policyTerms;
    }

    public function setPolicyTerms(PolicyTerms $policyTerms)
    {
        $this->policyTerms = $policyTerms;
    }

    public function getPromoCode()
    {
        return $this->promoCode;
    }

    public function setPromoCode($promoCode)
    {
        $this->promoCode = $promoCode;
    }

    public function getInvitations()
    {
        return $this->invitations;
    }

    public function getInvitationsAsArray()
    {
        // TODO: should be instanceof \Doctrine\Common\Collections\ArrayCollection, but not working
        if (is_object($this->getInvitations())) {
            return $this->getInvitations()->toArray();
        }

        return $this->getInvitations();
    }

    public function setPremium(Premium $premium)
    {
        $this->premium = $premium;
    }

    public function getPremium()
    {
        return $this->premium;
    }

    public function getIdentityLog()
    {
        return $this->identityLog;
    }

    public function setIdentityLog($identityLog)
    {
        $this->identityLog = $identityLog;
    }

    public function getScheduledPayments()
    {
        return $this->scheduledPayments;
    }

    public function addScheduledPayment(ScheduledPayment $scheduledPayment)
    {
        $scheduledPayment->setPolicy($this);
        $this->scheduledPayments[] = $scheduledPayment;
    }

    public function getSalvaPolicyNumbers()
    {
        return $this->salvaPolicyNumbers;
    }

    public function getLatestSalvaPolicyNumberVersion()
    {
        return count($this->salvaPolicyNumbers) + 1;
    }

    public function getSalvaPolicyNumberByDate(\DateTime $date)
    {
        return $this->getSalvaPolicyNumber($this->getSalvaVersion($date));
    }

    public function getSalvaPolicyNumber($version = null)
    {
        if (!$this->getPolicyNumber()) {
            return null;
        }
        if (!$version) {
            $version = $this->getLatestSalvaPolicyNumberVersion();
        }

        return sprintf("%s/%d", $this->getPolicyNumber(), $version);
    }

    public function incrementSalvaPolicyNumber(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $this->salvaPolicyNumbers[$this->getLatestSalvaPolicyNumberVersion()] = $date->format(\DateTime::ATOM);
    }

    public function getSalvaVersion(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $versions = $this->getSalvaPolicyNumbers();
        ksort($versions);
        foreach ($versions as $version => $versionDate) {
            if (new \DateTime($versionDate) > $date) {
                return $version;
            }
        }

        // Current version null
        return null;
    }

    public function init(User $user, PolicyDocument $terms, PolicyDocument $keyfacts)
    {
        $user->addPolicy($this);
        $this->setPolicyTerms($terms);
        $this->setPolicyKeyFacts($keyfacts);
    }

    public function create($seq, $prefix = null, $startDate = null)
    {
        if (!$prefix) {
            $prefix = $this->getPolicyNumberPrefix();
        }
        if (!$startDate) {
            $startDate = new \DateTime();
            $startDate->add(new \DateInterval('P10M'));
        }
        $this->setStart($startDate);
        $nextYear = clone $this->getStart();
        // This is same date/time but add 1 to the year
        $nextYear = $nextYear->modify('+1 year');
        $this->setEnd($nextYear);

        $initialPolicyNumber = 5500000;
        $this->setPolicyNumber(sprintf(
            "%s/%s/%d",
            $prefix,
            $this->getStart()->format("Y"),
            $initialPolicyNumber + $seq
        ));
        $this->setStatus(self::STATUS_PENDING);
    }

    public function getNumberOfInstallments()
    {
        if (!$this->isPolicy() || count($this->getPayments()) == 0) {
            return null;
        }

        return count($this->getScheduledPayments()) + 1;
    }

    public function getInstallmentAmount()
    {
        if (!$this->isPolicy() || count($this->getPayments()) == 0) {
            return null;
        }

        if ($this->getNumberOfInstallments() == 1) {
            return $this->getPremium()->getYearlyPremiumPrice();
        } elseif ($this->getNumberOfInstallments() == 12) {
            return $this->getPremium()->getMonthlyPremiumPrice();
        } else {
            return null;
        }
    }

    public function getRemainingTotalPremiumPrice($payments)
    {
        return $this->toTwoDp($this->getTotalPremiumPrice() - $this->getTotalPremiumPrice($payments));
    }

    public function getTotalPremiumPrice($payments = null)
    {
        if ($payments === null) {
            return $this->getPremium()->getYearlyPremiumPrice();
        }

        return $this->toTwoDp($this->getPremium()->getMonthlyPremiumPrice() * count($payments));
    }

    public function getRemainingTotalIpt($payments)
    {
        return $this->toTwoDp($this->getTotalIpt() - $this->getTotalIpt($payments));
    }

    public function getTotalIpt($payments = null)
    {
        if ($payments === null) {
            return $this->getPremium()->getTotalIpt();
        }

        return $this->toTwoDp($this->getPremium()->getIpt() * count($payments));
    }

    public function getRemainingTotalBrokerFee($payments)
    {
        return $this->toTwoDp($this->getTotalBrokerFee() - $this->getTotalBrokerFee($payments));
    }

    public function getTotalBrokerFee($payments = null)
    {
        if ($payments === null) {
            return Salva::YEARLY_BROKER_FEE;
        }

        return $this->toTwoDp(Salva::MONTHLY_BROKER_FEE * count($payments));
    }

    public function getPaymentsForSalvaVersions($multiArray = true)
    {
        $payments = [];
        $flatPayments = [];
        $paymentsUsed = [];
        $salvaPolicyNumbers = $this->getSalvaPolicyNumbers();
        foreach ($salvaPolicyNumbers as $version => $versionDate) {
            $payments[$version] = [];
            foreach ($this->getPayments() as $payment) {
                if ($payment->isSuccess()) {
                    if ($payment->getDate() < new \DateTime($versionDate) &&
                        !in_array($payment->getId(), $paymentsUsed)) {
                        $paymentsUsed[] = $payment->getId();
                        $payments[$version][] = $payment;
                        $flatPayments[] = $payment;
                    }
                }
            }
        }

        if ($multiArray) {
            return $payments;
        } else {
            return $flatPayments;
        }
    }

    public function getRemainingPremiumPaid($payments)
    {
        return $this->toTwoDp($this->getPremiumPaid() - $this->getPremiumPaid($payments));
    }

    public function getPremiumPaid($payments = null)
    {
        $paid = 0;
        if (!$this->isPolicy()) {
            return 0;
        }
        if ($payments === null) {
            $payments = $this->getPayments();
        }

        foreach ($payments as $payment) {
            if ($payment->isSuccess()) {
                $paid += $payment->getAmount();
            }
        }

        return $paid;
    }

    public function getRemainingBrokerFeePaid($payments)
    {
        return $this->toTwoDp($this->getBrokerFeePaid() - $this->getBrokerFeePaid($payments));
    }

    public function getBrokerFeePaid($payments = null)
    {
        $brokerFee = 0;
        if (!$this->isPolicy()) {
            return 0;
        }
        if ($payments === null) {
            $payments = $this->getPayments();
        }

        foreach ($payments as $payment) {
            if ($payment->isSuccess()) {
                $brokerFee += $payment->getBrokerFee();
            }
        }

        return $this->toTwoDp($brokerFee);
    }

    public function getRiskColour()
    {
        $risk = $this->getRisk();
        if ($risk == self::RISK_LEVEL_LOW) {
            return 'green';
        } elseif ($risk == self::RISK_LEVEL_MEDIUM) {
            return 'yellow';
        } elseif ($risk == self::RISK_LEVEL_HIGH) {
            return 'red';
        }
    }

    public function getRisk($date = null)
    {
        if (!isset(static::$riskLevels[$this->getRiskReason($date)])) {
            return null;
        }

        return static::$riskLevels[$this->getRiskReason($date)];
    }

    public function getRiskReason($date = null)
    {
        if (!$this->isPolicy()) {
            return null;
        }

        if ($date == null) {
            $date = new \DateTime();
        }

        if (count($this->getConnections()) > 0) {
            // Connected and value of their pot is zero
            if ($this->hasMonetaryClaimed(true)) {
                // a self claim can be before the pot is adjusted.  also a pot zero is not always due to a self claim
                return self::RISK_CONNECTED_SELF_CLAIM;
                // return self::RISK_LEVEL_HIGH;
            } elseif ($this->getPotValue() == 0) {
                return self::RISK_CONNECTED_POT_ZERO;
            }

            if ($this->hasNetworkClaim(true, true)) {
                // Connected and value of their pot is £10 following a claim in the past month
                if ($this->hasNetworkClaimedInLast30Days($date, true)) {
                    return self::RISK_CONNECTED_RECENT_NETWORK_CLAIM;
                    // return self::RISK_LEVEL_MEDIUM;
                } else {
                    // Connected and value of their pot is £10 following a claim which took place over a month ago
                    return self::RISK_CONNECTED_ESTABLISHED_NETWORK_CLAIM;
                    //return self::RISK_LEVEL_LOW;
                }
            }

            // Connected and no claims in their network
            return self::RISK_CONNECTED_NO_CLAIM;
            // return self::RISK_LEVEL_LOW;
        }

        // Claim within first month of buying policy, has no connections and has made no attempts to make connections
        if ($this->isPolicyWithin30Days($date)) {
            return self::RISK_NOT_CONNECTED_NEW_POLICY;
            //return self::RISK_LEVEL_HIGH;
        } else {
            // No connections & claiming after the 1st month
            return self::RISK_NOT_CONNECTED_ESTABLISHED_POLICY;
            // return self::RISK_LEVEL_MEDIUM;
        }
    }

    public function isPolicyWithin30Days($date = null)
    {
        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getStart()->diff($date)->days <= 30;
    }

    public function isPolicyWithin60Days($date = null)
    {
        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getStart()->diff($date)->days < 60;
    }

    public function isBeforePolicyStarted($date = null)
    {
        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getStart() > $date;
    }

    public function getConnectionCliffDate()
    {
        if (!$this->getStart()) {
            return null;
        }

        $cliffDate = clone $this->getStart();
        // add 60 days
        $cliffDate->add(new \DateInterval('P60D'));

        return $cliffDate;
    }

    public function hasMonetaryClaimed($includeOpen = false)
    {
        foreach ($this->claims as $claim) {
            if ($claim->isMonetaryClaim($includeOpen)) {
                return true;
            }
        }

        return false;
    }

    public function isCancelled()
    {
        return $this->getStatus() == self::STATUS_CANCELLED;
    }

    public function hasEndedInLast30Days($date = null)
    {
        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getEnd()->diff($date)->days < 30;
    }

    public function getUnreplacedConnectionCancelledPolicyInLast30Days($date = null)
    {
        foreach ($this->getConnections() as $connection) {
            if ($connection->getReplacementUser()) {
                continue;
            }
            $policy = $connection->getLinkedPolicy();
            if (!$policy) {
                throw new \Exception(sprintf('Invalid connection in policy %s', $this->getId()));
            }
            if ($policy->isCancelled() && $policy->hasEndedInLast30Days($date)) {
                return $connection;
            }
        }

        return null;
    }

    public function hasNetworkClaimedInLast30Days($date = null, $includeOpen = false)
    {
        if ($date == null) {
            $date = new \DateTime();
        }

        foreach ($this->getNetworkClaims(true, $includeOpen) as $claim) {
            if ($claim->isWithin30Days($date)) {
                return true;
            }
        }

        return false;
    }

    public function hasNetworkClaim($monitaryOnly = false, $includeOpen = false)
    {
        return count($this->getNetworkClaims($monitaryOnly, $includeOpen)) > 0;
    }

    public function hasMonetaryNetworkClaim()
    {
        return $this->hasNetworkClaim(true);
    }

    public function getNetworkClaims($monitaryOnly = false, $includeOpen = false)
    {
        $claims = [];
        foreach ($this->getConnections() as $connection) {
            $policy = $connection->getLinkedPolicy();
            if (!$policy) {
                throw new \Exception(sprintf('Invalid connection in policy %s', $this->getId()));
            }
            foreach ($policy->getClaims() as $claim) {
                if (!$monitaryOnly || $claim->isMonetaryClaim($includeOpen)) {
                    $claims[] = $claim;
                }
            }
        }

        return $claims;
    }

    public function getNetworkCancellations()
    {
        $policies = [];
        foreach ($this->getConnections() as $connection) {
            $policy = $connection->getLinkedPolicy();
            if (!$policy) {
                throw new \Exception(sprintf('Invalid connection in policy %s', $this->getId()));
            }
            if ($policy->isCancelled()) {
                $policies[] = $policy;
            }
        }

        return $policies;
    }

    public function calculatePotValue($promoValueOnly = false)
    {
        $potValue = 0;
        // TODO: How does a cancelled policy affect networked connections?  Would the connection be withdrawn?
        foreach ($this->connections as $connection) {
            if ($promoValueOnly) {
                $potValue += $connection->getPromoValue();
            } else {
                $potValue += $connection->getTotalValue();
            }
        }

        $networkClaimCount = count($this->getNetworkClaims(true));

        // Pot is 0 if you claim
        // Pot is £10 if you don't claim, but there's only 1 claim in your network and your pot is >= £40
        // Pot is 0 if networks claims > 1 or if network claims is 1 and your pot < £40
        if ($this->hasMonetaryClaimed()) {
            $potValue = 0;
        } elseif ($networkClaimCount > 0 && $promoValueOnly) {
            // we don't want marketing spend to be valuated in the case of a claim
            $potValue = 0;
        } elseif ($networkClaimCount == 1 && $potValue >= 40) {
            $potValue = 10;
        } elseif ($networkClaimCount > 0) {
            $potValue = 0;
        }

        return $potValue;
    }

    public function updatePotValue()
    {
        $this->setPotValue($this->calculatePotValue());
        $this->setPromoPotValue($this->calculatePotValue(true));
    }

    public function isPolicy()
    {
        return $this->getStatus() !== null && $this->getPremium() !== null;
    }

    public function isValidPolicy()
    {
        if (!$this->isPolicy()) {
            return false;
        }

        return strpos($this->getPolicyNumber(), $this->getPolicyNumberPrefix()) === 0;
    }

    public function getSentInvitations()
    {
        $userId = $this->getUser() ? $this->getUser()->getId() : null;
        return array_filter($this->getInvitationsAsArray(), function ($invitation) use ($userId) {
            if ($invitation->isProcessed()) {
                return false;
            }

            if ($inviter = $invitation->getInviter()) {
                return $inviter->getId() == $userId;
            }

            return false;
        });
    }

    /**
     * Update the policy itself, however, this should be done via the policy server in order to
     * send out all the emails, etc
     *
     * @param string    $reason CANCELLED_*
     * @param \DateTime $date
     *
     */
    public function cancel($reason, \DateTime $date = null)
    {
        if (!$this->getId()) {
            throw new \Exception('Unable to cancel a policy that is missing an id');
        }

        if ($date == null) {
            $date = new \DateTime();
        }
        $this->setStatus(Policy::STATUS_CANCELLED);
        $this->setCancelledReason($reason);
        $this->setEnd($date);

        // For now, just lock the user.  May want to allow the user to login in the future though...
        $user = $this->getUser();
        $user->setLocked(true);

        // zero out the connection value for connections bound to this policy
        foreach ($this->getConnections() as $networkConnection) {
            $networkConnection->clearValue();
            foreach ($networkConnection->getLinkedPolicy()->getConnections() as $otherConnection) {
                if ($otherConnection->getLinkedPolicy()->getId() == $this->getId()) {
                    $otherConnection->clearValue();
                }
            }
            $networkConnection->getLinkedPolicy()->updatePotValue();
        }

        $this->updatePotValue();
    }

    abstract public function getMaxConnections();
    abstract public function getMaxPot();
    abstract public function getConnectionValue();
    abstract public function getPolicyNumberPrefix();

    public function isPotCompletelyFilled()
    {
        if (!$this->isPolicy()) {
            throw new \Exception('Not yet a policy - does not make sense to check this now.');
        }
        return $this->getPotValue() == $this->getMaxPot();
    }

    public function getConnectionValues()
    {
        $connectionValues = [];
        if (!$this->isPolicy()) {
            return $connectionValues;
        }

        $connectionValues[] = [
            'start_date' => $this->getStart() ? $this->getStart()->format(\DateTime::ATOM) : null,
            'end_date' => $this->getConnectionCliffDate() ?
                $this->getConnectionCliffDate()->format(\DateTime::ATOM) :
                null,
            'value' => $this->getTotalConnectionValue($this->getStart()),
        ];

        $afterCliffDate = clone $this->getConnectionCliffDate();
        $afterCliffDate->add(new \DateInterval('PT1S'));
        $connectionValues[] = [
            'start_date' => $this->getConnectionCliffDate() ?
                $this->getConnectionCliffDate()->format(\DateTime::ATOM) :
                null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format(\DateTime::ATOM) : null,
            'value' => $this->getConnectionValue($afterCliffDate),
        ];

        return $connectionValues;
    }

    protected function toApiArray()
    {
        if ($this->isPolicy() && !$this->getPolicyTerms()) {
            throw new \Exception(sprintf('Policy %s is missing terms', $this->getId()));
        }
        if ($this->isPolicy() && !$this->getPolicyKeyFacts()) {
            throw new \Exception(sprintf('Policy %s is missing keyfacts', $this->getId()));
        }

        return [
            'id' => $this->getId(),
            'status' => $this->getStatus(),
            'type' => 'phone',
            'start_date' => $this->getStart() ? $this->getStart()->format(\DateTime::ATOM) : null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format(\DateTime::ATOM) : null,
            'policy_number' => $this->getPolicyNumber(),
            'monthly_premium' => $this->getPremium()->getMonthlyPremiumPrice(),
            'policy_terms_id' => $this->getPolicyTerms() ? $this->getPolicyTerms()->getId() : null,
            'policy_keyfacts_id' => $this->getPolicyKeyFacts() ? $this->getPolicyKeyFacts()->getId() : null,
            'pot' => [
                'connections' => count($this->getConnections()),
                'max_connections' => $this->getMaxConnections(),
                'value' => $this->getPotValue(),
                'max_value' => $this->getMaxPot(),
                'historical_max_value' => $this->getHistoricalMaxPotValue(),
                'connection_values' => $this->getConnectionValues(),
            ],
            'connections' => $this->eachApiArray($this->getConnections()),
            'sent_invitations' => $this->eachApiArray($this->getSentInvitations()),
            'promo_code' => $this->getPromoCode(),
            'has_claim' => $this->hasMonetaryClaimed(),
            'has_network_claim' => $this->hasNetworkClaim(true),
        ];
    }
}
