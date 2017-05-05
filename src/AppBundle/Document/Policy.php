<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Doctrine\ODM\MongoDB\PersistentCollection;
use Gedmo\Mapping\Annotation as Gedmo;
use AppBundle\Document\File\S3File;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Classes\Salva;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Connection\StandardConnection;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PolicyRepository")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("policy_type")
 * @MongoDB\DiscriminatorMap({"phone"="PhonePolicy","salva-phone"="SalvaPhonePolicy"})
 * @Gedmo\Loggable
 */
abstract class Policy
{
    use ArrayToApiArrayTrait;
    use CurrencyTrait;
    use DateTrait;

    const RISK_LEVEL_HIGH = 'high';
    const RISK_LEVEL_MEDIUM = 'medium';
    const RISK_LEVEL_LOW = 'low';

    const RISK_CONNECTED_POT_ZERO = 'connected pot £0';
    const RISK_CONNECTED_SELF_CLAIM = 'claim on policy';
    const RISK_CONNECTED_RECENT_NETWORK_CLAIM = 'connected w/recent claim';
    const RISK_CONNECTED_ESTABLISHED_NETWORK_CLAIM = 'connected w/established claim (>30 days)';
    const RISK_CONNECTED_NO_CLAIM = 'connected w/no claim';
    const RISK_NOT_CONNECTED_NEW_POLICY = 'not connected w/new policy';
    const RISK_NOT_CONNECTED_ESTABLISHED_POLICY = 'not connected w/established policy (>30 days)';
    const RISK_PENDING_CANCELLATION_POLICY = 'policy is pending cancellation';

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_UNPAID = 'unpaid';
    const STATUS_MULTIPAY_REQUESTED = 'multipay-requested';
    const STATUS_MULTIPAY_REJECTED = 'multipay-rejected';

    const CANCELLED_UNPAID = 'unpaid';
    const CANCELLED_ACTUAL_FRAUD = 'actual-fraud';
    const CANCELLED_SUSPECTED_FRAUD = 'suspected-fraud';
    const CANCELLED_USER_REQUESTED = 'user-requested';
    const CANCELLED_COOLOFF = 'cooloff';
    const CANCELLED_BADRISK = 'badrisk';
    const CANCELLED_DISPOSSESSION = 'dispossession';
    const CANCELLED_WRECKAGE = 'wreckage';

    const PLAN_MONTHLY = 'monthly';
    const PLAN_YEARLY = 'yearly';

    const PREFIX_INVALID = 'INVALID';

    // First 1000 policies
    const PROMO_LAUNCH = 'launch';
    // Free month (refund) for Nov 2016
    const PROMO_FREE_NOV = 'free-nov';
    // Launch promo + Free month (refund) for Nov 2016
    const PROMO_LAUNCH_FREE_NOV = 'launch-free-nov';
    // Free month (refund) for Dec 2016
    const PROMO_FREE_DEC_2016 = 'free-dec-2016';
    // £5 for policies purchased feb 2016-apr 2017 when login to app
    const PROMO_APP_MARCH_2017 = 'app-download-mar-2017';

    public static $riskLevels = [
        self::RISK_CONNECTED_POT_ZERO => self::RISK_LEVEL_HIGH,
        self::RISK_CONNECTED_SELF_CLAIM => self::RISK_LEVEL_HIGH,
        self::RISK_CONNECTED_RECENT_NETWORK_CLAIM => self::RISK_LEVEL_MEDIUM,
        self::RISK_CONNECTED_ESTABLISHED_NETWORK_CLAIM => self::RISK_LEVEL_LOW,
        self::RISK_CONNECTED_NO_CLAIM => self::RISK_LEVEL_LOW,
        self::RISK_NOT_CONNECTED_NEW_POLICY => self::RISK_LEVEL_HIGH,
        self::RISK_NOT_CONNECTED_ESTABLISHED_POLICY => self::RISK_LEVEL_MEDIUM,
        self::RISK_PENDING_CANCELLATION_POLICY => self::RISK_LEVEL_HIGH,
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
     * @MongoDB\ReferenceOne(targetDocument="Company", inversedBy="policies")
     * @Gedmo\Versioned
     */
    protected $company;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="payerPolicies")
     * @Gedmo\Versioned
     */
    protected $payer;

    /**
     * @Assert\Choice({"pending", "active", "cancelled", "expired", "unpaid",
     *                  "multipay-requested", "multipay-rejected"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @Assert\Choice({
     *  "unpaid", "actual-fraud", "suspected-fraud", "user-requested",
     *  "cooloff", "badrisk", "dispossession", "wreckage"
     * }, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $cancelledReason;

    /**
     * @Assert\Regex(pattern="/^[a-zA-Z]+\/\d{4,4}\/\d{5,20}$/")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true, sparse=true)
     * @Gedmo\Versioned
     */
    protected $policyNumber;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="0", max="50")
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
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Connection\Connection", cascade={"persist"})
     */
    protected $connections = array();

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Claim", cascade={"persist"})
     */
    protected $claims = array();

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $start;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $end;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $staticEnd;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     * @Gedmo\Versioned
     */
    protected $pendingCancellation;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float", nullable=false)
     * @Gedmo\Versioned
     */
    protected $potValue;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float", nullable=false)
     * @Gedmo\Versioned
     */
    protected $historicalMaxPotValue;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float", nullable=false)
     * @Gedmo\Versioned
     */
    protected $promoPotValue;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="5", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $promoCode;

    /**
     * @Assert\Range(min=0,max=12)
     * @MongoDB\Field(type="integer")
     * @Gedmo\Versioned
     */
    protected $premiumInstallments;

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

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     */
    protected $lastEmailed;

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\SCode",
     *  cascade={"persist"}
     * )
     */
    protected $scodes = array();

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\File\S3File",
     *  cascade={"persist"}
     * )
     */
    protected $policyFiles = array();

    /**
     * @Assert\Choice({"invitation", "scode"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $leadSource;

    /**
     * @MongoDB\Field(type="hash")
     */
    protected $notes = array();

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

    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Although rare, payments can include refunds
     */
    public function getPayments()
    {
        return $this->payments;
    }

    public function addPayment($payment)
    {
        $payment->setPolicy($this);
        $payment->calculateSplit();

        // For some reason, payment was being added twice for ::testNewPolicyJudopayUnpaidRepayOk
        // perhaps an issue with cascade persist
        // seems to have no ill effects and resolves the issue
        if ($this->payments->contains($payment)) {
            throw new \Exception('duplicate payment');
        }

        $this->payments->add($payment);
    }

    /**
     * Successful Payments
     */
    public function getSuccessfulPayments()
    {
        $payments = $this->getPayments();
        if (is_object($payments)) {
            $payments = $payments->toArray();
        }
        if (!$this->getPayments()) {
            return [];
        }

        return array_filter($payments, function ($payment) {
            return $payment->isSuccess();
        });
    }

    /**
     * Failed Payments
     */
    public function getFailedPayments()
    {
        $payments = $this->getPayments();
        if (is_object($payments)) {
            $payments = $payments->toArray();
        }
        if (!$this->getPayments()) {
            return [];
        }

        return array_filter($payments, function ($payment) {
            return !$payment->isSuccess();
        });
    }

    /**
     * Payments filtered by credits (pos amount)
     */
    public function getSuccessfulPaymentCredits()
    {
        return array_filter($this->getSuccessfulPayments(), function ($payment) {
            return $payment->getAmount() > 0 && !$payment instanceof SoSurePayment;
        });
    }

    /**
     * Payments filtered by credits (pos amount)
     */
    public function getFailedPaymentCredits()
    {
        return array_filter($this->getFailedPayments(), function ($payment) {
            return $payment->getAmount() > 0 && !$payment instanceof SoSurePayment;
        });
    }

    public function getFirstSuccessfulPaymentCredit()
    {
        $payments = $this->getSuccessfulPaymentCredits();
        if (count($payments) == 0) {
            return null;
        }

        // sort older to more recent
        usort($payments, function ($a, $b) {
            return $a->getDate() > $b->getDate();
        });
        //\Doctrine\Common\Util\Debug::dump($payments, 3);

        return $payments[0];
    }

    public function getLastSuccessfulPaymentCredit()
    {
        $payments = $this->getSuccessfulPaymentCredits();
        if (count($payments) == 0) {
            return null;
        }

        // sort more recent to older
        usort($payments, function ($a, $b) {
            return $a->getDate() < $b->getDate();
        });
        //\Doctrine\Common\Util\Debug::dump($payments, 3);

        return $payments[0];
    }

    /**
     * Payments filtered by debits (neg amount)
     */
    public function getSuccessfulPaymentDebits()
    {
        return array_filter($this->getSuccessfulPayments(), function ($payment) {
            return $payment->getAmount() <= 0 && !$payment instanceof SoSurePayment;
        });
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function getPayer()
    {
        return $this->payer;
    }

    public function getCompany()
    {
        return $this->company;
    }

    public function setCompany(Company $company)
    {
        $this->company = $company;
    }

    public function setPayer(User $user)
    {
        $this->payer = $user;
    }

    public function getStart()
    {
        return $this->start;
    }

    public function getStartForBilling()
    {
        return $this->adjustDayForBilling($this->getStart());
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

    public function getStaticEnd()
    {
        return $this->staticEnd;
    }

    public function setStaticEnd(\DateTime $staticEnd)
    {
        $this->staticEnd = $staticEnd;
    }

    public function getPendingCancellation()
    {
        return $this->pendingCancellation;
    }

    public function setPendingCancellation(\DateTime $pendingCancellation = null)
    {
        $this->pendingCancellation = $pendingCancellation;
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
        $connection->setSourceUser($this->getUser());
        $this->connections[] = $connection;
    }

    public function getConnections()
    {
        return $this->connections;
    }

    public function getStandardConnections()
    {
        $connections = [];
        foreach ($this->getConnections() as $connection) {
            if ($connection instanceof StandardConnection) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }

    public function getRewardConnections()
    {
        $connections = [];
        foreach ($this->getConnections() as $connection) {
            if ($connection instanceof RewardConnection) {
                $connections[] = $connection;
            }
        }

        return $connections;
    }

    public function getLastConnection()
    {
        $connections = $this->getConnections();
        if (!is_array($connections)) {
            $connections = $connections->getValues();
        }
        if (count($connections) == 0) {
            return null;
        }

        // sort more recent to older
        usort($connections, function ($a, $b) {
            return $a->getDate() < $b->getDate();
        });
        //\Doctrine\Common\Util\Debug::dump($payments, 3);

        return $connections[0];
    }

    public function addClaim(Claim $claim)
    {
        if (!$this->isClaimAllowed($claim)) {
            throw new \Exception(sprintf('This policy can not have any additional lost/theft claims'));
        }

        $claim->setPolicy($this);
        $this->claims[] = $claim;
    }

    public function getClaims()
    {
        return $this->claims;
    }

    public function isClaimAllowed($claim)
    {
        if (!$claim->isLostTheft()) {
            return true;
        }

        return $this->isAdditionalClaimLostTheftApprovedAllowed();
    }

    public function isAdditionalClaimLostTheftApprovedAllowed()
    {
        $count = 0;
        foreach ($this->getClaims() as $claim) {
            if ($claim->isLostTheftApproved()) {
                $count++;
            }
        }

        return $count < 2;
    }

    public function hasOpenClaim($onlyWithOutFees = false)
    {
        foreach ($this->getClaims() as $claim) {
            if ($claim->isOpen()) {
                if ($onlyWithOutFees) {
                    return $claim->getLastChargeAmount() ==  0;
                } else {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasSuspectedFraudulentClaim()
    {
        foreach ($this->getClaims() as $claim) {
            if ($claim->getSuspectedFraud()) {
                return true;
            }
        }

        return false;
    }

    public function getApprovedClaims($includeSettled = true)
    {
        $claims = [];
        foreach ($this->getClaims() as $claim) {
            if ($claim->getStatus() == Claim::STATUS_APPROVED ||
                ($includeSettled && $claim->getStatus() == Claim::STATUS_SETTLED)) {
                $claims[] = $claim;
            }
        }

        return $claims;
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

    public function getNextScheduledPayment()
    {
        $next = null;
        foreach ($this->getScheduledPayments() as $scheduledPayment) {
            if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED) {
                if (!$next || $next->getScheduled() > $scheduledPayment->getScheduled()) {
                    $next = $scheduledPayment;
                }
            }
        }

        return $next;
    }

    public function getAllScheduledPayments($status)
    {
        $scheduledPayments = [];
        foreach ($this->getScheduledPayments() as $scheduledPayment) {
            if ($scheduledPayment->getStatus() == $status) {
                $scheduledPayments[] = $scheduledPayment;
            }
        }

        return $scheduledPayments;
    }

    public function getLastEmailed()
    {
        return $this->lastEmailed;
    }

    public function setLastEmailed($lastEmailed)
    {
        $this->lastEmailed = $lastEmailed;
    }

    public function getPremiumInstallments()
    {
        return $this->premiumInstallments;
    }

    public function setPremiumInstallments($premiumInstallments)
    {
        $this->premiumInstallments = $premiumInstallments;
    }

    public function getSCodes()
    {
        return $this->scodes;
    }

    public function getActiveSCodes()
    {
        $scodes = [];
        foreach ($this->getSCodes() as $scode) {
            if ($scode->isActive()) {
                $scodes[] = $scode;
            }
        }

        return $scodes;
    }

    public function createAddSCode($scodeCount)
    {
        $scode = new SCode();
        $scode->generateNamedCode($this->getUser(), $scodeCount);
        $this->addSCode($scode);
    }

    public function addSCode(SCode $scode)
    {
        $scode->setPolicy($this);
        $this->scodes[] = $scode;
    }

    public function getStandardSCode()
    {
        foreach ($this->scodes as $scode) {
            //\Doctrine\Common\Util\Debug::dump($scode);
            if ($scode->isActive() && $scode->isStandard()) {
                return $scode;
            }
        }

        return null;
    }

    public function getPolicyFiles()
    {
        return $this->policyFiles;
    }

    public function addPolicyFile(S3File $file)
    {
        $this->policyFiles[] = $file;
    }

    public function setLeadSource($source)
    {
        $this->leadSource = $source;
    }

    public function getLeadSource()
    {
        return $this->leadSource;
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function addNote($note)
    {
        $now = new \DateTime();
        $this->notes[$now->getTimestamp()] = $note;
    }

    public function setId($id)
    {
        if ($this->id) {
            throw new \Exception('Can not reasssign id');
        }

        $this->id = $id;
    }

    public function getExpectedBillingDate()
    {
        // TODO: consolidate with PolicyService::generateScheduledPayments
        $date = clone $this->getStart();

        // To allow billing on same date every month, 28th is max allowable day on month
        $date = $this->adjustDayForBilling($date);

        return $date;
    }

    public function getNextBillingDate($date, $filtered = true)
    {
        $nextDate = new \DateTime();
        $this->clearTime($nextDate);
        if ($this->getPremiumPlan() == self::PLAN_MONTHLY) {
            $nextDate->setDate($date->format('Y'), $date->format('m'), $this->getStart()->format('d'));
            if ($nextDate < $date) {
                $nextDate->add(new \DateInterval('P1M'));
            }

            // To allow billing on same date every month, 28th is max allowable day on month
            if ($filtered) {
                $nextDate = $this->adjustDayForBilling($nextDate);
            }
        } elseif ($this->getPremiumPlan() == self::PLAN_YEARLY) {
            $nextDate->setDate($date->format('Y'), $this->getStart()->format('m'), $this->getStart()->format('d'));
            if ($nextDate < $date) {
                $nextDate->add(new \DateInterval('P1Y'));
            }
        }

        return $nextDate;
    }

    public function init(User $user, PolicyDocument $terms)
    {
        $user->addPolicy($this);
        if ($company = $user->getCompany()) {
            $company->addPolicy($this);
        }
        $this->setPolicyTerms($terms);
    }

    public function create($seq, $prefix = null, \DateTime $startDate = null, $scodeCount = 1)
    {
        if (!$this->getUser()) {
            throw new \Exception('Missing user for policy');
        }
        if (!$this->getUser()->getBillingAddress()) {
            throw new \Exception('User must have a billing address');
        }

        // Only create 1 time
        if ($this->getPolicyNumber()) {
            return;
        }

        if (!$prefix) {
            $prefix = $this->getPolicyNumberPrefix();
        }
        if (!$startDate) {
            $startDate = new \DateTime();
            // No longer necessary to start 10 minutes in the future
            // $startDate->add(new \DateInterval('PT10M'));
        }

        // TODO: move to SalvaPhonePolicy
        // salva needs a end time of 23:59 in local time
        $startDate->setTimezone(new \DateTimeZone(Salva::SALVA_TIMEZONE));

        $this->setStart($startDate);
        $nextYear = clone $this->getStart();
        // This is same date/time but add 1 to the year
        $nextYear = $nextYear->modify('+1 year');
        $nextYear->modify("-1 day");
        $nextYear->setTime(23, 59, 59);
        $this->setEnd($nextYear);
        $this->setStaticEnd($nextYear);

        // Premium and/or IPT rate may have changed
        $this->validatePremium(true, $startDate);

        $initialPolicyNumber = 5500000;
        $this->setPolicyNumber(sprintf(
            "%s/%s/%d",
            $prefix,
            $this->getStart()->format("Y"),
            $initialPolicyNumber + $seq
        ));
        $this->setStatus(self::STATUS_PENDING);
        if (count($this->getSCodes()) == 0) {
            $this->createAddSCode($scodeCount);
        }

        $this->setLeadSource($this->getUser()->getLeadSource());
    }

    abstract public function validatePremium($adjust, \DateTime $date);

    public function getPremiumInstallmentCount()
    {
        if (!$this->isPolicy()) {
            return null;
        }

        return $this->getPremiumInstallments();
    }

    public function getPremiumInstallmentPrice()
    {
        if (!$this->isPolicy()) {
            return null;
        }

        if (!$this->getPremiumInstallmentCount()) {
            return null;
        } elseif ($this->getPremiumInstallmentCount() == 1) {
            return $this->getPremium()->getYearlyPremiumPrice();
        } elseif ($this->getPremiumInstallmentCount() == 12) {
            return $this->getPremium()->getMonthlyPremiumPrice();
        } else {
            throw new \Exception(sprintf('Policy %s does not have correct installment amount', $this->getId()));
        }
    }

    public function getPremiumGwpInstallmentPrice()
    {
        if (!$this->isPolicy()) {
            return null;
        }

        if (!$this->getPremiumInstallmentCount()) {
            return null;
        } elseif ($this->getPremiumInstallmentCount() == 1) {
            return $this->getPremium()->getYearlyGwp();
        } elseif ($this->getPremiumInstallmentCount() == 12) {
            return $this->getPremium()->getGwp();
        } else {
            throw new \Exception(sprintf('Policy %s does not have correct installment amount', $this->getId()));
        }
    }

    public function getYearlyPremiumPrice()
    {
        return $this->getPremiumInstallmentCount() * $this->getPremiumInstallmentPrice();
    }

    public function getPremiumPlan()
    {
        if ($this->getPremiumInstallments() == 1) {
            return self::PLAN_YEARLY;
        } elseif ($this->getPremiumInstallments() == 12) {
            return self::PLAN_MONTHLY;
        } else {
            return null;
        }
    }

    public function isRefundAllowed()
    {
        // For all cases, if there's a claim, then no not allow refund
        // TODO: Should this be open claim???  Possibly shouldn't allow cancellation if there is an option claim
        if ($this->hasMonetaryClaimed(true)) {
            return false;
        }

        if ($this->getCancelledReason() == Policy::CANCELLED_UNPAID ||
            $this->getCancelledReason() == Policy::CANCELLED_ACTUAL_FRAUD ||
            $this->getCancelledReason() == Policy::CANCELLED_SUSPECTED_FRAUD) {
            // Never refund for certain cancellation reasons
            return false;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_USER_REQUESTED) {
            // user has 30 days from when they requested cancellation
            // however, as we don't easily have a scheduled cancellation
            // we will start with a manual cancellation that should be done
            // 30 days after they requested, such that the cancellation will be immediate then
            return true;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            return true;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_DISPOSSESSION ||
            $this->getCancelledReason() == Policy::CANCELLED_WRECKAGE) {
            return true;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_BADRISK) {
            throw new \UnexpectedValueException('Badrisk is not implemented');
        }
    }

    public function getRefundAmount($date = null)
    {
        // Just in case - make sure we don't refund for non-cancelled policies
        if (!$this->isCancelled()) {
            return 0;
        }

        if (!$date) {
            $date = new \DateTime();
        }

        if (!$this->isRefundAllowed()) {
            return 0;
        }

        // 3 factors determine refund amount
        // Cancellation Reason, Monthly/Annual, Claimed/NotClaimed

        if ($this->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            return $this->getCooloffRefundAmount();
        } else {
            return $this->getProratedRefundAmount($date);
        }
    }

    public function getRefundCommissionAmount($date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        // Just in case - make sure we don't refund for non-cancelled policies
        if (!$this->isCancelled()) {
            return 0;
        }

        if (!$this->isRefundAllowed()) {
            return 0;
        }

        // 3 factors determine refund amount
        // Cancellation Reason, Monthly/Annual, Claimed/NotClaimed

        if ($this->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            return $this->getCooloffRefundCommissionAmount();
        } else {
            return $this->getProratedRefundCommissionAmount($date);
        }
    }

    public function getCooloffRefundAmount()
    {
        // Cooloff should refund full amount (which should be equal to the last payment)
        $paymentToRefund = $this->getLastSuccessfulPaymentCredit();
        $this->validateRefundAmountIsInstallmentPrice($paymentToRefund);
        $amount = $paymentToRefund->getAmount();
        $paid = $this->getPremiumPaid();

        // we should never refund more than the user paid
        // especially relevent for cases with an automatic free month
        if ($amount > $paid) {
            return $paid;
        }

        return $paymentToRefund->getAmount();
    }

    public function getProratedRefundAmount($date = null)
    {
        $used = $this->getPremium()->getYearlyPremiumPrice() * $this->getProrataMultiplier($date);
        $paid = $this->getPremiumPaid();

        return $this->toTwoDp($paid - $used);
    }

    public function getCooloffRefundCommissionAmount()
    {
        // Cooloff should refund full amount (which should be equal to the last payment)
        $paymentToRefund = $this->getLastSuccessfulPaymentCredit();
        $this->validateRefundAmountIsInstallmentPrice($paymentToRefund);
        $amount = $paymentToRefund->getAmount();
        $paid = $this->getPremiumPaid();

        // we should never refund more than the user paid
        // especially relevent for cases with an automatic free month
        if ($amount > $paid) {
            return $this->getTotalCommissionPaid();
        }

        return $paymentToRefund->getTotalCommission();
    }

    public function getProratedRefundCommissionAmount($date = null)
    {
        // TODO: either make abstract to get the total commission, or move to a db collection
        $used = Salva::YEARLY_TOTAL_COMMISSION * $this->getProrataMultiplier($date);
        $paid = $this->getTotalCommissionPaid();

        return $this->toTwoDp($paid - $used);
    }

    public function getDaysInPolicyYear()
    {
        if (!$this->isPolicy() || !$this->getStart()) {
            throw new \Exception('Unable to determine days in policy as policy is not valid');
        }

        $leapYear = $this->getStart()->format('L');
        if ($this->getStart()->format('m') > 2) {
            $leapYear = $this->getStaticEnd()->format('L');
        }

        if ($leapYear === '1') {
            return 366;
        } else {
            return 365;
        }
    }

    public function getProrataMultiplier($date)
    {
        return $this->getDaysInPolicy($date) / $this->getDaysInPolicyYear();
    }

    public function getDaysInPolicy($date)
    {
        if (!$this->isPolicy() || !$this->getStart()) {
            throw new \Exception('Unable to determine days in policy as policy is not valid');
        }
        if (!$date) {
            $date = new \DateTime();
        }
        $date = $this->endOfDay($date);

        $start = $this->startOfDay($this->getStart());
        $diff = $start->diff($date);
        $days = $diff->days;

        return $days;
    }

    public function getRemainingPremiumPaid($payments)
    {
        return $this->toTwoDp($this->getPremiumPaid() - $this->getPremiumPaid($payments));
    }

    public function getPremiumPaid($payments = null)
    {
        $paid = 0;
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

    public function getGwpPaid($payments = null)
    {
        $gwp = 0;
        if (!$this->isPolicy()) {
            return 0;
        }
        if ($payments === null) {
            $payments = $this->getPayments();
        }

        foreach ($payments as $payment) {
            if ($payment->isSuccess()) {
                $gwp += $payment->getGwp();
            }
        }

        return $gwp;
    }

    public function getRemainingTotalCommissionPaid($payments)
    {
        return $this->toTwoDp($this->getTotalCommissionPaid() - $this->getTotalCommissionPaid($payments));
    }

    public function getTotalCommissionPaid($payments = null)
    {
        $totalCommission = 0;
        if (!$this->isPolicy()) {
            return 0;
        }
        if ($payments === null) {
            $payments = $this->getPayments();
        }

        foreach ($payments as $payment) {
            if ($payment->isSuccess()) {
                $totalCommission += $payment->getTotalCommission();
            }
        }

        return $this->toTwoDp($totalCommission);
    }

    public function getOutstandingPremium()
    {
        return $this->toTwoDp($this->getPremium()->getYearlyPremiumPrice() - $this->getPremiumPaid());
    }

    public function isInitialPayment(\DateTime $date = null)
    {
        if ($this->areEqualToFourDp($this->getTotalSuccessfulPayments($date), $this->getPremiumInstallmentPrice())) {
            return true;
        } else {
            return false;
        }
    }

    public function isFinalMonthlyPayment()
    {
        if ($this->getPremiumPlan() != self::PLAN_MONTHLY) {
            return false;
        }

        // If there's 1 payment outstanding
        if ($this->areEqualToFourDp($this->getOutstandingPremium(), $this->getPremiumInstallmentPrice())) {
            return true;
        } else {
            return false;
        }
    }

    public function getRiskColour()
    {
        $risk = $this->getRisk();
        if ($risk == self::RISK_LEVEL_LOW) {
            return 'green';
        } elseif ($risk == self::RISK_LEVEL_MEDIUM) {
            return '#ff9500';
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

        if ($this->getPendingCancellation()) {
            return self::RISK_PENDING_CANCELLATION_POLICY;
        }

        if (count($this->getStandardConnections()) > 0) {
            // Connected and value of their pot is zero
            if ($this->hasMonetaryClaimed(true)) {
                // a self claim can be before the pot is adjusted.  also a pot zero is not always due to a self claim
                return self::RISK_CONNECTED_SELF_CLAIM;
                // return self::RISK_LEVEL_HIGH;
            } elseif ($this->areEqualToFourDp($this->getPotValue(), 0) ||
                $this->areEqualToFourDp($this->getPotValue() - $this->getPromoPotValue(), 0)) {
                // pot is empty, or pot is entirely made up of promo values
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

    public function isPolicyWithin21Days($date = null)
    {
        if (!$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getStart()->diff($date)->days <= 21;
    }

    public function isPolicyWithin30Days($date = null)
    {
        if (!$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getStart()->diff($date)->days <= 30;
    }

    public function isPolicyWithin60Days($date = null)
    {
        if (!$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getStart()->diff($date)->days < 60;
    }

    public function isBeforePolicyStarted($date = null)
    {
        if (!$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getStart() > $date;
    }

    public function getConnectionCliffDate($pseudo = false)
    {
        if (!$this->getStart()) {
            return null;
        }

        $cliffDate = clone $this->getStart();

        // pseudo cliff date for displaying a 14 day message
        if ($pseudo) {
            // add 14 days
            $cliffDate->add(new \DateInterval('P14D'));
        } else {
            // add 60 days
            $cliffDate->add(new \DateInterval('P60D'));
        }

        return $cliffDate;
    }

    public function hasMonetaryClaimed($includeOpen = false)
    {
        return count($this->getMonetaryClaimed($includeOpen)) > 0;
    }

    public function getMonetaryClaimed($includeOpen = false)
    {
        $claims = [];
        foreach ($this->claims as $claim) {
            if ($claim->isMonetaryClaim($includeOpen)) {
                $claims[] = $claim;
            }
        }

        return $claims;
    }

    public function isClaimInProgress()
    {
        foreach ($this->claims as $claim) {
            if ($claim->isOpen()) {
                return true;
            }
        }

        return false;
    }

    public function isExpired()
    {
        return $this->getStatus() == self::STATUS_EXPIRED;
    }

    public function isCancelled()
    {
        return $this->getStatus() == self::STATUS_CANCELLED;
    }

    public function isEnded()
    {
        return $this->isExpired() || $this->isCancelled();
    }

    public function isCancelledWithUserDeclined()
    {
        return $this->getStatus() == self::STATUS_CANCELLED && in_array($this->getCancelledReason(), [
            self::CANCELLED_ACTUAL_FRAUD,
            self::CANCELLED_SUSPECTED_FRAUD,
            self::CANCELLED_UNPAID,
            self::CANCELLED_BADRISK
        ]);
    }

    public function isCooloffCancelled()
    {
        return $this->isCancelled() && $this->getCancelledReason() == self::CANCELLED_COOLOFF;
    }

    public function canCancel($reason, $date = null)
    {
        // Doesn't make sense to cancel
        if (in_array($this->getStatus(), [self::STATUS_CANCELLED, self::STATUS_EXPIRED])) {
            return false;
        }

        // Any claims must be completed before cancellation is allowed
        if ($this->isClaimInProgress()) {
            return false;
        }

        if ($reason == Policy::CANCELLED_COOLOFF) {
            return $this->isWithinCooloffPeriod($date) && !$this->hasMonetaryClaimed(true);
        }

        if ($reason == Policy::CANCELLED_UNPAID) {
            return $this->getStatus() == self::STATUS_UNPAID;
        }

        if ($reason == Policy::CANCELLED_BADRISK) {
            return false;
        }

        return true;
    }

    public function validateRefundAmountIsInstallmentPrice($payment)
    {
        if (!$this->areEqualToTwoDp($payment->getAmount(), $this->getPremiumInstallmentPrice())) {
            throw new \InvalidArgumentException(sprintf(
                'Failed to validate [policy %s] refund amount (%f) does not match premium price (%f)',
                $this->getPolicyNumber(),
                $payment->getAmount(),
                $this->getPremiumInstallmentPrice() ? $this->getPremiumInstallmentPrice() : -1
            ));
        }

        return true;
    }

    public function isWithinCooloffPeriod($date = null)
    {
        if (!$this->isPolicy() || !$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getStart()->diff($date)->days < 14;
    }

    public function hasEndedInLast30Days($date = null)
    {
        if ($date == null) {
            $date = new \DateTime();
        }

        return $this->getEnd()->diff($date)->days < 30;
    }

    public function shouldExpirePolicy($prefix = null, $date = null)
    {
        if (!$this->isValidPolicy($prefix) || $this->getPremiumPlan() != self::PLAN_MONTHLY) {
            return false;
        }

        // if its a valid policy without a payment, probably it should be expired
        if (!$this->getLastSuccessfulPaymentCredit()) {
            throw new \Exception(sprintf(
                'Policy %s does not have a success payment - should be expired?',
                $this->getId()
            ));
        }

        if ($date == null) {
            $date = new \DateTime();
        }

        return $date >= $this->getPolicyExpirationDate($date);
    }

    /**
     * Note that expiration date is for cancellations only and may be after policy end date
     * Only makes sense if policy is a monthly plan
     */
    public function getPolicyExpirationDate(\DateTime $date = null)
    {
        if ($this->getPremiumPlan() == self::PLAN_YEARLY) {
            return null;
        }
        if (!$date) {
            $date = new \DateTime();
        }

        $billingDate = $this->getNextBillingDate($date);
        $maxCount = $this->dateDiffMonths($billingDate, $this->getStart());

        // print $billingDate->format(\DateTime::ATOM) . PHP_EOL;
        while ($this->getOutstandingPremiumToDate($billingDate) > 0 && !$this->areEqualToTwoDp(
            $this->getOutstandingPremiumToDate($billingDate),
            0
        )) {
            $billingDate = $billingDate->sub(new \DateInterval('P1M'));
            // print $billingDate->format(\DateTime::ATOM) . PHP_EOL;
            // print $this->getOutstandingPremiumToDate($billingDate) . PHP_EOL;

            // Ensure we don't loop indefinitely
            $maxCount--;
            if ($maxCount < 0) {
                throw new \Exception(sprintf(
                    'Failed to find a date with a 0 outstanding premium (%f). Policy %s/%s',
                    $this->getOutstandingPremiumToDate($billingDate),
                    $this->getPolicyNumber(),
                    $this->getId()
                ));
                // Older method of using the last payment recevied date to determine expiration
                // $billingDate = clone $this->getLastSuccessfulPaymentCredit()->getDate();
                // $billingDate->add(new \DateInterval('P1M'));
                // break;
            }
        }
        // print $billingDate->format(\DateTime::ATOM) . PHP_EOL;

        // and business rule of 30 days unpaid before auto cancellation
        $billingDate->add(new \DateInterval('P30D'));

        return $billingDate;
    }

    public function getPolicyExpirationDateDays(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $diff = $this->getPolicyExpirationDate()->diff($date);
        $days = $diff->days;

        return $days;
    }

    public function getUnreplacedConnectionCancelledPolicyInLast30Days($date = null)
    {
        foreach ($this->getConnections() as $connection) {
            if ($connection->getReplacementUser() || $connection instanceof RewardConnection) {
                continue;
            }
            $policy = $connection->getLinkedPolicy();
            if (!$policy && !$connection instanceof RewardConnection) {
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
        foreach ($this->getStandardConnections() as $connection) {
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
            if ($connection instanceof RewardConnection) {
                continue;
            }
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

    public function getPolicyPrefix($environment)
    {
        $prefix = null;
        if ($environment != 'prod') {
            $prefix = strtoupper($environment);
        } elseif ($this->getUser() && $this->getUser()->hasSoSureEmail()) {
            // any emails with @so-sure.com will generate an invalid policy
            $prefix = self::PREFIX_INVALID;
        }

        return $prefix;
    }

    public function hasPolicyPrefix($prefix = null)
    {
        if (!$prefix) {
            $prefix = $this->getPolicyNumberPrefix();
        }

        // TODO: Should this be up to / ?
        return strpos($this->getPolicyNumber(), $prefix) === 0;
    }

    public function isPrefixInvalidPolicy()
    {
        return $this->hasPolicyPrefix(self::PREFIX_INVALID);
    }

    public function isValidPolicy($prefix = null)
    {
        if (!$this->isPolicy()) {
            return false;
        }

        return $this->hasPolicyPrefix($prefix);
    }

    public function isBillablePolicy()
    {
        // We should only bill policies that are active or unpaid
        // Doesn't make sense to bill expired or cancelled policies
        return in_array($this->getStatus(), [self::STATUS_ACTIVE, self::STATUS_UNPAID]);
    }

    public function getSentInvitations($onlyProcessed = true)
    {
        $userId = $this->getUser() ? $this->getUser()->getId() : null;
        return array_filter($this->getInvitationsAsArray(), function ($invitation) use ($userId, $onlyProcessed) {
            if ($onlyProcessed && $invitation->isProcessed()) {
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

        if (!$this->canCancel($reason, $date)) {
            throw new \Exception(sprintf(
                'Unable to cancel policy %s/%s. Is claim in progress?',
                $this->getPolicyNumber(),
                $this->getId()
            ));
        }

        if ($date == null) {
            $date = new \DateTime();
        }
        $this->setStatus(Policy::STATUS_CANCELLED);
        $this->setCancelledReason($reason);
        $this->setEnd($date);

        // User declined cancellations should lock the user record
        $user = $this->getUser();
        if ($this->isCancelledWithUserDeclined()) {
            $user->setLocked(true);
        }

        // zero out the connection value for connections bound to this policy
        foreach ($this->getConnections() as $networkConnection) {
            $networkConnection->clearValue();
            if ($networkConnection instanceof RewardConnection) {
                continue;
            }
            foreach ($networkConnection->getLinkedPolicy()->getConnections() as $otherConnection) {
                if ($otherConnection->getLinkedPolicy()->getId() == $this->getId()) {
                    $otherConnection->clearValue();
                }
            }
            $networkConnection->getLinkedPolicy()->updatePotValue();
        }

        // Cancel any scheduled payments
        foreach ($this->getScheduledPayments() as $scheduledPayment) {
            if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED) {
                $scheduledPayment->setStatus(ScheduledPayment::STATUS_CANCELLED);
            }
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
        return $this->areEqualToFourDp($this->getPotValue(), $this->getMaxPot());
    }

    public function getCurrentConnectionValues()
    {
        $now = new \DateTime();
        $now = $now->format('U');
        foreach ($this->getConnectionValues('U') as $connectionValue) {
            if ($now >= $connectionValue['start_date'] && $now <= $connectionValue['end_date']) {
                return $connectionValue;
            }
        }

        return null;
    }

    public function getConnectionValues($format = \DateTime::ATOM)
    {
        $connectionValues = [];
        if (!$this->isPolicy() || !$this->getStart()) {
            return $connectionValues;
        }
        $now = new \DateTime();
        $startDate = $this->getStart();
        if ($startDate && $startDate > $now) {
            $startDate = $now;
        }

        $firstCliffDate = $this->getConnectionCliffDate(true);
        $afterFirstCliffDate = $this->addOneSecond($firstCliffDate);

        $secondCliffDate = $this->getConnectionCliffDate(false);
        $afterSecondCliffDate = $this->addOneSecond($secondCliffDate);

        $connectionValues[] = [
            'start_date' => $startDate ? $startDate->format($format) : null,
            'end_date' => $firstCliffDate ?
                $firstCliffDate->format($format) :
                null,
            'value' => $this->getTotalConnectionValue($startDate),
            'teaser' => 'until the Ideal Connection Time expires',
            // @codingStandardsIgnoreStart
            'description' => 'For the best chance of filling your Reward Pot we recommend making all your connections in the first 2 weeks!',
            // @codingStandardsIgnoreEnd
        ];

        $connectionValues[] = [
            'start_date' => $firstCliffDate ?
                $firstCliffDate->format($format) :
                null,
            'end_date' => $secondCliffDate ?
                $secondCliffDate->format($format) :
                null,
            'value' => $this->getTotalConnectionValue($afterFirstCliffDate),
            'teaser' => 'until your Connection Bonus is reduced',
            'description' => sprintf(
                // @codingStandardsIgnoreStart
                "Connections are £%d during this time & only £%d afterwards – so signup your friends before it's too late!",
                // @codingStandardsIgnoreEnd
                $this->getTotalConnectionValue($afterFirstCliffDate),
                $this->getTotalConnectionValue($afterSecondCliffDate)
            ),
        ];

        $connectionValues[] = [
            'start_date' => $secondCliffDate ?
                $secondCliffDate->format($format) :
                null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format($format) : null,
            'value' => $this->getTotalConnectionValue($afterSecondCliffDate),
            'teaser' => '',
            'description' => '',
        ];

        return $connectionValues;
    }

    public function getTotalSuccessfulPayments(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $totalPaid = 0;
        foreach ($this->getSuccessfulPayments() as $payment) {
            if ($payment->getDate() <= $date) {
                $totalPaid += $payment->getAmount();
            }
        }

        return $totalPaid;
    }

    public function getTotalExpectedPaidToDate(\DateTime $date = null)
    {
        if (!$this->isPolicy() || !$this->getStart()) {
            return null;
        }

        if (!$date) {
            $date = new \DateTime();
        }
        $date = $this->adjustDayForBilling($date, true);

        $expectedPaid = 0;
        if ($this->getPremiumPlan() == self::PLAN_YEARLY) {
            $expectedPaid = $this->getPremiumInstallmentPrice();
        } elseif ($this->getPremiumPlan() == self::PLAN_MONTHLY) {
            $months = $this->dateDiffMonths($date, $this->getStartForBilling());
            // print PHP_EOL;
            // print $date->format(\DateTime::ATOM) . PHP_EOL;
            // print $this->getStartForBilling()->format(\DateTime::ATOM) . PHP_EOL;
            // print $months . PHP_EOL;
            $expectedPaid = $this->getPremiumInstallmentPrice() * $months;
        } else {
            throw new \Exception('Unknown premium plan');
        }

        return $expectedPaid;
    }

    public function getOutstandingPremiumToDate(\DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return null;
        }

        $totalPaid = $this->getTotalSuccessfulPayments($date);
        $expectedPaid = $this->getTotalExpectedPaidToDate($date);

        $diff = $expectedPaid - $totalPaid;
        if ($diff < 0) {
            return 0;
        }

        return $diff;
    }

    public function canInvite()
    {
        if ($this->isPotCompletelyFilled()) {
            return false;
        }
        if ($this->hasMonetaryClaimed()) {
            return false;
        }

        return true;
    }

    public function hasCorrectPolicyStatus(\DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return null;
        }
        $ignoredStatuses = [
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_MULTIPAY_REJECTED,
            self::STATUS_MULTIPAY_REQUESTED
        ];

        // mostly concerned with Active vs Unpaid
        if (in_array($this->getStatus(), $ignoredStatuses)) {
            return null;
        }

        if ($this->getStatus() == self::STATUS_PENDING) {
            return false;
        }

        if ($this->isPolicyPaidToDate($date)) {
            return $this->getStatus() == self::STATUS_ACTIVE;
        } else {
            return $this->getStatus() == self::STATUS_UNPAID;
        }
    }

    public function isPolicyPaidToDate(\DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return null;
        }

        $totalPaid = $this->getTotalSuccessfulPayments($date);
        $expectedPaid = $this->getTotalExpectedPaidToDate($date);
        // print sprintf("%f =? %f", $totalPaid, $expectedPaid) . PHP_EOL;

        // >= doesn't quite allow for minor float differences
        return $this->areEqualToTwoDp($expectedPaid, $totalPaid) || $totalPaid > $expectedPaid;
    }

    public function arePolicyScheduledPaymentsCorrect($prefix = null, \DateTime $date = null)
    {
        $scheduledPayments = $this->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);
        $totalScheduledPayments = ScheduledPayment::sumScheduledPaymentAmounts($scheduledPayments, $prefix);

        $outstanding = $this->getYearlyPremiumPrice() - $this->getTotalSuccessfulPayments($date);
        //print sprintf("%f ?= %f\n", $outstanding, $totalScheduledPayments);

        return $this->areEqualToTwoDp($outstanding, $totalScheduledPayments);
    }

    public function getSupportWarnings()
    {
        $warnings = ['Ensure DPA is validated (Intercom - DPA message). If cancellation requested, and DPA not validated, referrer to Dylan for retention.'];
        if ($this->hasOpenClaim(true)) {
            $warnings[] = sprintf('Policy has an open claim. Check & Notify Davies prior to cancellation (do not cancel if phone was shipped).');
        }
        if ($this->hasMonetaryClaimed()) {
            $warnings[] = sprintf('Policy has had a sucessful claim. Do NOT allow cancellation.');
        }
        if ($this->hasSuspectedFraudulentClaim()) {
            $warnings[] = sprintf('Policy has had a suspected fraudulent claim. Do NOT allow policy upgrade.');
        }

        return $warnings;
    }

    public function getClaimsWarnings()
    {
        $warnings = [];
        if (!$this->isPolicyPaidToDate() || $this->getStatus() == self::STATUS_UNPAID) {
            $warnings[] = sprintf('Policy is NOT paid to date - Policy must be paid to date prior to approval');
        }

        if (in_array($this->getStatus(), [self::STATUS_CANCELLED, self::STATUS_EXPIRED])) {
            $warnings[] = sprintf('Policy is %s - DO NOT ALLOW CLAIM', $this->getStatus());
        }

        if ($this->hasOpenClaim(true)) {
            $warnings[] = sprintf('Policy has an open claim, but Recipero (ClaimsCheck) has not been run.');
        }

        if (!$this->isAdditionalClaimLostTheftApprovedAllowed()) {
            $warnings[] = sprintf('Policy already has 2 lost/theft claims. No further lost/theft claims are allowed');
        }

        if ($this->getPendingCancellation()) {
            $warnings[] = sprintf(
                'Policy is scheduled to be cancelled on %s (requested by user 30 days prior).',
                $this->getPendingCancellation()->format('d M Y H:i')
            );
        }

        if ($this->isPolicyWithin21Days()) {
            $warnings[] = sprintf(
                'Policy was created within the last 21 days. High risk of fraud.'
            );
        }

        if ($this->hasSuspectedFraudulentClaim()) {
            $warnings[] = sprintf(
                'Policy has a suspected fraudulent claim.'
            );
        }

        return $warnings;
    }

    public function isPotValueCorrect()
    {
        return $this->getPotValue() == $this->calculatePotValue() &&
            $this->getPromoPotValue() == $this->calculatePotValue(true);
    }

    public function getPremiumPayments()
    {
        return [
            'paid' => $this->eachApiArray($this->getPayments()),
            'scheduled' => $this->eachApiArray($this->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED)),
        ];
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
            'start_date' => $this->getStart() ? $this->getStart()->format(\DateTime::ATOM) : null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format(\DateTime::ATOM) : null,
            'policy_number' => $this->getPolicyNumber(),
            'monthly_premium' => $this->getPremium()->getMonthlyPremiumPrice(),
            'policy_terms_id' => $this->getPolicyTerms() ? $this->getPolicyTerms()->getId() : null,
            'pot' => [
                'connections' => count($this->getConnections()),
                'max_connections' => $this->getMaxConnections(),
                'value' => $this->getPotValue(),
                'max_value' => $this->getMaxPot(),
                'historical_max_value' => $this->getHistoricalMaxPotValue(),
                'connection_values' => $this->getConnectionValues(),
            ],
            'connections' => $this->eachApiArray($this->getConnections(), $this->getNetworkClaims()),
            'sent_invitations' => $this->eachApiArray($this->getSentInvitations()),
            'promo_code' => $this->getPromoCode(),
            'has_claim' => $this->hasMonetaryClaimed(),
            'has_network_claim' => $this->hasNetworkClaim(true),
            'claim_dates' => $this->eachApiMethod($this->getMonetaryClaimed(), 'getClosedDate'),
            'yearly_premium' => $this->getPremium()->getYearlyPremiumPrice(),
            'premium' => $this->getPremiumInstallmentPrice(),
            'premium_plan' => $this->getPremiumPlan(),
            'scodes' => $this->eachApiArray($this->getActiveSCodes()),
            'premium_payments' => $this->getPremiumPayments(),
            'premium_gwp' => $this->getPremiumGwpInstallmentPrice(),
            'facebook_filters' => $this->eachApiMethod($this->getSentInvitations(), 'getInviteeFacebookId', false),
        ];
    }

    public static function sumYearlyPremiumPrice($policies, $prefix = null)
    {
        $total = 0;
        foreach ($policies as $policy) {
            if ($policy->isValidPolicy($prefix)) {
                $total += $policy->getYearlyPremiumPrice();
            }
        }

        return $total;
    }
}
