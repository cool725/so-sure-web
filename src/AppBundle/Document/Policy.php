<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Doctrine\ODM\MongoDB\PersistentCollection;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("policy_type")
 * @MongoDB\DiscriminatorMap({"phone"="PhonePolicy"})
 */
abstract class Policy
{
    use ArrayToApiArrayTrait;
    use CurrencyTrait;

    const RISK_LEVEL_HIGH = 'high';
    const RISK_LEVEL_MEDIUM = 'medium';
    const RISK_LEVEL_LOW = 'low';

    const RISK_CONNECTED_POT_ZERO = 'connected pot £0';
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

    // First 1000 policies
    const PROMO_LAUNCH = 'launch';

    const PAYMENT_DD_MONTHLY = 'gocardless_monthly';

    public static $riskLevels = [
        self::RISK_CONNECTED_POT_ZERO => self::RISK_LEVEL_HIGH,
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
     * @MongoDB\ReferenceMany(targetDocument="Payment", mappedBy="policy")
     */
    protected $payments;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="policies")
     */
    protected $user;

    /** @MongoDB\Field(type="string") */
    protected $status;

    /** @MongoDB\Field(type="string", name="cancelled_reason") */
    protected $cancelledReason;

    /**
     * @MongoDB\Field(type="string", name="policy_number")
     * @MongoDB\Index(unique=true, sparse=true)
     */
    protected $policyNumber;

    /** @MongoDB\Field(type="string", name="payment_type", nullable=true) */
    protected $paymentType;

    /** @MongoDB\Field(type="string", name="gocardless_mandate", nullable=true) */
    protected $gocardlessMandate;

    /** @MongoDB\Field(type="string", name="gocardless_subscription", nullable=true) */
    protected $gocardlessSubscription;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Invitation\Invitation", mappedBy="policy")
     */
    protected $invitations;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\PolicyDocument")
     */
    protected $policyDocuments;

    /**
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\Connection")
     */
    protected $connections = array();

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Claim", cascade={"persist"})
     */
    protected $claims = array();

    /** @MongoDB\Date() */
    protected $created;

    /** @MongoDB\Date(nullable=true) */
    protected $start;

    /** @MongoDB\Date(nullable=true) */
    protected $end;

    /** @MongoDB\Field(type="float", name="pot_value", nullable=false) */
    protected $potValue;

    /** @MongoDB\Field(type="float", name="historical_max_pot_value", nullable=false) */
    protected $historicalMaxPotValue;

    /** @MongoDB\Field(type="string") */
    protected $promoCode;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Premium")
     */
    protected $premium;

    /** @MongoDB\EmbedOne(targetDocument="IdentityLog", name="identity_log") */
    protected $identityLog;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->payments = new \Doctrine\Common\Collections\ArrayCollection();
        $this->invitations = new \Doctrine\Common\Collections\ArrayCollection();
        $this->policyDocuments = new \Doctrine\Common\Collections\ArrayCollection();
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
        return $this->potValue;
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

    public function getPolicyDocuments()
    {
        return $this->policyDocuments;
    }

    public function getPolicyKeyFacts()
    {
        $policyDocuments = $this->getPolicyDocuments();
        foreach ($policyDocuments as $policyDocument) {
            if ($policyDocument instanceof PolicyKeyFacts) {
                return $policyDocument;
            }
        }

        return null;
    }

    public function getPolicyTerms()
    {
        $policyDocuments = $this->getPolicyDocuments();
        foreach ($policyDocuments as $policyDocument) {
            if ($policyDocument instanceof PolicyTerms) {
                return $policyDocument;
            }
        }

        return null;
    }

    public function addPolicyDocument(PolicyDocument $policyDocument)
    {
        $this->policyDocuments[] = $policyDocument;
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

    public function init(User $user, PolicyDocument $terms, PolicyDocument $keyfacts)
    {
        $user->addPolicy($this);
        $this->addPolicyDocument($terms);
        $this->addPolicyDocument($keyfacts);
    }

    public function create($seq, $startDate = null)
    {
        if (!$startDate) {
            $startDate = new \DateTime();
        }
        $this->setStart($startDate);
        $nextYear = clone $this->getStart();
        // This is same date/time but add 1 to the year
        $nextYear = $nextYear->modify('+1 year');
        // strip the time, so should be 00:00 of current day (e.g. midnight of previous day)
        $midnight = new \DateTime($nextYear->format('Y-m-d'));
        $midnight->modify("-1 second");
        $this->setEnd($midnight);

        $initialPolicyNumber = 5500000;
        $this->setPolicyNumber(sprintf("Mob/%s/%d", $this->getStart()->format("Y"), $initialPolicyNumber + $seq));
        $this->setStatus(self::STATUS_PENDING);
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
            if ($this->getPotValue() == 0) {
                return self::RISK_CONNECTED_POT_ZERO;
                // return self::RISK_LEVEL_HIGH;
            }

            if ($this->hasNetworkClaim(true)) {
                // Connected and value of their pot is £10 following a claim in the past month
                if ($this->hasNetworkClaimedInLast30Days($date)) {
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

    public function hasMonetaryClaimed()
    {
        foreach ($this->claims as $claim) {
            if ($claim->isMonetaryClaim()) {
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
            $policy = $connection->getPolicy();
            if (!$policy) {
                throw new \Exception(sprintf('Invalid connection in policy %s', $this->getId()));
            }
            if ($policy->isCancelled() && $policy->hasEndedInLast30Days($date)) {
                return $connection;
            }
        }

        return null;
    }

    public function hasNetworkClaimedInLast30Days($date = null)
    {
        if ($date == null) {
            $date = new \DateTime();
        }

        foreach ($this->getNetworkClaims(true) as $claim) {
            if ($claim->isWithin30Days($date)) {
                return true;
            }
        }

        return false;
    }

    public function hasNetworkClaim($monitaryOnly = false)
    {
        return count($this->getNetworkClaims($monitaryOnly)) > 0;
    }

    public function getNetworkClaims($monitaryOnly = false)
    {
        $claims = [];
        foreach ($this->getConnections() as $connection) {
            $policy = $connection->getPolicy();
            if (!$policy) {
                throw new \Exception(sprintf('Invalid connection in policy %s', $this->getId()));
            }
            foreach ($policy->getClaims() as $claim) {
                if (!$monitaryOnly || $claim->isMonetaryClaim()) {
                    $claims[] = $claim;
                }
            }
        }

        return $claims;
    }

    public function calculatePotValue()
    {
        $potValue = 0;
        // TODO: How does a cancelled policy affect networked connections?  Would the connection be withdrawn?
        foreach ($this->connections as $connection) {
            $potValue += $connection->getValue();
        }

        $networkClaimCount = count($this->getNetworkClaims(true));

        // Pot is 0 if you claim
        // Pot is £10 if you don't claim, but there's only 1 claim in your network and your pot is >= £40
        // Pot is 0 if networks claims > 1 or if network claims is 1 and your pot < £40
        if ($this->hasMonetaryClaimed()) {
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
    }

    public function isPolicy()
    {
        return $this->getStatus() !== null && $this->getPremium() !== null;
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

    abstract public function getMaxConnections();
    abstract public function getMaxPot();
    abstract public function getConnectionValue();

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
            'start_date' => $this->getStart() ? $this->getStart()->format(\DateTime::ISO8601) : null,
            'end_date' => $this->getConnectionCliffDate() ?
                $this->getConnectionCliffDate()->format(\DateTime::ISO8601) :
                null,
            'value' => $this->getConnectionValue($this->getStart()),
        ];

        $afterCliffDate = clone $this->getConnectionCliffDate();
        $afterCliffDate->add(new \DateInterval('PT1S'));
        $connectionValues[] = [
            'start_date' => $this->getConnectionCliffDate() ?
                $this->getConnectionCliffDate()->format(\DateTime::ISO8601) :
                null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format(\DateTime::ISO8601) : null,
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
            'start_date' => $this->getStart() ? $this->getStart()->format(\DateTime::ISO8601) : null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format(\DateTime::ISO8601) : null,
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
