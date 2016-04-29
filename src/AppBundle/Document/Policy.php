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

    const RISK_HIGH = 'high';
    const RISK_MEDIUM = 'medium';
    const RISK_LOW = 'low';

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_UNPAID = 'unpaid';

    // First 1000 policies
    const PROMO_LAUNCH = 'launch';

    const PAYMENT_DD_MONTHLY = 'gocardless_monthly';

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
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\PolicyTerms")
     */
    protected $policyTerms;

    /**
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\Connection")
     */
    protected $connections = array();

    /**
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\Claim")
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

    /** @MongoDB\Field(type="string") */
    protected $promoCode;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Premium")
     */
    protected $premium;

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
        if ($risk == self::RISK_LOW) {
            return 'green';
        } elseif ($risk == self::RISK_MEDIUM) {
            return 'yellow';
        } elseif ($risk == self::RISK_HIGH) {
            return 'red';
        }
    }

    public function getRisk($date = null)
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
                return self::RISK_HIGH;
            }

            if ($this->getLatestClaim()) {
                // Connected and value of their pot is £10 following a claim in the past month
                if ($this->hasClaimedInLast30Days($date)) {
                    return self::RISK_MEDIUM;
                } else {
                    // Connected and value of their pot is £10 following a claim which took place over a month ago
                    return self::RISK_LOW;
                }
            }

            // Connected and no claims in their network
            return self::RISK_LOW;
        }

        // Claim within first month of buying policy, has no connections and has made no attempts to make connections
        if ($this->isPolicyWithin30Days($date)) {
            return self::RISK_HIGH;
        } else {
            // No connections & claiming after the 1st month
            return self::RISK_MEDIUM;
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

    public function hasClaimedInLast30Days($date = null)
    {
        if ($date == null) {
            $date = new \DateTime();
        }

        // TODO: Adjust this - should ignore non-monitary claims
        if (!$latestClaim = $this->getLatestClaim()) {
            return false;
        }

        return $latestClaim->getDate()->diff($date)->days < 30;
    }

    public function getLatestClaim()
    {
        if (!$this->getClaims() || count($this->getClaims()) == 0) {
            return null;
        }

        $claims = $this->getClaims();
        if ($claims instanceof PersistentCollection) {
            $claims = $claims->getValues();
        }
        uasort($claims, function ($a, $b) {
            return $a->getDate() < $b->getDate();
        });

        return array_values($claims)[0];
    }

    public function calculatePotValue()
    {
        $potValue = 0;
        // TODO: How does a cancelled policy affect networked connections?  Would the connection be withdrawn?
        foreach ($this->connections as $connection) {
            $potValue += $connection->getValue();
        }

        $networkClaimCount = 0;
        foreach ($this->getConnections() as $connection) {
            $policy = $connection->getPolicy();
            if (!$policy) {
                throw new \Exception(sprintf('Invalid connection in policy %s', $this->getId()));
            }
            if ($policy->hasMonetaryClaimed()) {
                $networkClaimCount++;
            }
        }

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

        return [
            'id' => $this->getId(),
            'status' => $this->getStatus(),
            'type' => 'phone',
            'start_date' => $this->getStart() ? $this->getStart()->format(\DateTime::ISO8601) : null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format(\DateTime::ISO8601) : null,
            'policy_number' => $this->getPolicyNumber(),
            'monthly_premium' => $this->getPremium()->getMonthlyPremiumPrice(),
            'policy_terms_id' => $this->getPolicyTerms() ? $this->getPolicyTerms()->getId() : null,
            'pot' => [
                'connections' => count($this->getConnections()),
                'max_connections' => $this->getMaxConnections(),
                'value' => $this->getPotValue(),
                'max_value' => $this->getMaxPot(),
                'connection_values' => $this->getConnectionValues(),
            ],
            'connections' => $this->eachApiArray($this->getConnections()),
            'sent_invitations' => $this->eachApiArray($this->getSentInvitations()),
        ];
    }
}
