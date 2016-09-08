<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Doctrine\ODM\MongoDB\PersistentCollection;
use Gedmo\Mapping\Annotation as Gedmo;
use AppBundle\Document\File\S3File;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Classes\Salva;

/**
 * @MongoDB\Document
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
    const RISK_NOT_CONNECTED_ESTABLISHED_POLICY = 'not connected w/established polish (>30 days)';

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED = 'expired';
    const STATUS_UNPAID = 'unpaid';

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

    // First 1000 policies
    const PROMO_LAUNCH = 'launch';

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
     * @Assert\Choice({"pending", "active", "cancelled", "expired", "unpaid"})
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $status;

    /**
     * @Assert\Choice({
     *  "unpaid", "actual-fraud", "suspected-fraud", "user-requested",
     *  "cooloff", "badrisk", "dispossession", "wreckage"
     * })
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
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Connection", cascade={"persist"})
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
     * Payments filtered by credits (pos amount)
     */
    public function getSuccessfulPaymentCredits()
    {
        return array_filter($this->getSuccessfulPayments(), function ($payment) {
            return $payment->getAmount() > 0;
        });
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
            return $payment->getAmount() <= 0;
        });
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

    public function getStaticEnd()
    {
        return $this->staticEnd;
    }

    public function setStaticEnd(\DateTime $staticEnd)
    {
        $this->staticEnd = $staticEnd;
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

    public function init(User $user, PolicyDocument $terms)
    {
        $user->addPolicy($this);
        $this->setPolicyTerms($terms);
    }

    public function create($seq, $prefix = null, \DateTime $startDate = null)
    {
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

        $initialPolicyNumber = 5500000;
        $this->setPolicyNumber(sprintf(
            "%s/%s/%d",
            $prefix,
            $this->getStart()->format("Y"),
            $initialPolicyNumber + $seq
        ));
        $this->setStatus(self::STATUS_PENDING);
        if (count($this->getSCodes()) == 0) {
            $this->addSCode(new SCode());
        }
    }

    public function getPolicyLength()
    {
        if (!$this->isPolicy()) {
            return null;
        }

        $diff = $this->getEnd()->diff($this->getStart());
        $months = $diff->y * 12 + $diff->m + $diff->d / 30;

        return (int) ceil($months);
    }

    public function getPremiumInstallmentCount()
    {
        if (!$this->isPolicy() || count($this->getPayments()) == 0) {
            return null;
        }

        return $this->getPremiumInstallments();
    }

    public function getPremiumInstallmentPrice()
    {
        if (!$this->isPolicy() || count($this->getPayments()) == 0) {
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

    public function getRefundAmount($date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        // Just in case - make sure we don't refund for non-cancelled policies
        if (!$this->isCancelled()) {
            return 0;
        }

        // 3 factors determine refund amount
        // Cancellation Reason, Monthly/Annual, Claimed/NotClaimed
        if ($this->getCancelledReason() == Policy::CANCELLED_UNPAID ||
            $this->getCancelledReason() == Policy::CANCELLED_ACTUAL_FRAUD ||
            $this->getCancelledReason() == Policy::CANCELLED_SUSPECTED_FRAUD) {
            // Never refund for certain cancellation reasons
            return 0;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_USER_REQUESTED) {
            // user has 30 days from when they requested cancellation
            // however, as we don't easily have a scheduled cancellation
            // we will start with a manual cancellation that should be done
            // 30 days after they requested, such that the cancellation will be immediate then
            if ($this->getPremiumPlan() == self::PLAN_MONTHLY) {
                return 0;
            } elseif ($this->getPremiumPlan() == self::PLAN_YEARLY) {
                if ($this->hasMonetaryClaimed(true)) {
                    return 0;
                } else {
                    // Still need to validate rules with Salva - for now, don't refund
                    // return $this->monthlyProratedRefundAmount($date);
                    return 0;
                }
            }
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            // Cooloff should always refund full amount (which should be equal to the last payment)
            $paymentToRefund = $this->getLastSuccessfulPaymentCredit();
            $this->validateRefundAmountIsInstallmentPrice($paymentToRefund);

            return $paymentToRefund->getAmount();
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_DISPOSSESSION ||
            $this->getCancelledReason() == Policy::CANCELLED_WRECKAGE) {
            if ($this->hasMonetaryClaimed(true)) {
                return 0;
            } else {
                // Still need to validate rules with Salva - for now, don't refund
                /*
                if ($this->getPremiumPlan() == self::PLAN_MONTHLY) {
                    $paymentToRefund = $this->getLastSuccessfulPaymentCredit();
                    $this->validateRefundAmountIsInstallmentPrice($paymentToRefund);
    
                    return $paymentToRefund->getAmount();
                } elseif ($this->getPremiumPlan() == self::PLAN_YEARLY) {
                    return $this->monthlyProratedRefundAmount($date);
                }
                */
                return 0;
            }
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_BADRISK) {
            throw new \UnexpectedValueException('Badrisk is not implemented');
        }
    }

    public function monthlyProratedRefundAmount($date = null)
    {
        $remainingMonths = $this->getRemainingMonthsInPolicy($date);
        // Refund is from next month's anniversary, so subtracting 1 month from remaning months is same
        $remainingMonths--;
        if ($remainingMonths >= 1) {
            return $this->getPremium()->getMonthlyPremiumPrice() * $remainingMonths;
        } else {
            return 0;
        }
    }

    public function getRemainingMonthsInPolicy($date)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        // Policy end date may already be changed to now, so use the original end date
        $months = $date->diff($this->getStaticEnd())->m;

        return $months;
    }

    public function getRemainingTotalPremiumPrice($payments)
    {
        return $this->toTwoDp($this->getTotalPremiumPrice() - $this->getTotalPremiumPrice($payments));
    }

    public function getTotalPremiumPrice($payments = null)
    {
        return $this->getPremium()->sumPremiumPrice($this->getMonthsForCancellationCalc($payments));
    }

    public function getRemainingTotalGwp($payments)
    {
        return $this->toTwoDp($this->getTotalGwp() - $this->getTotalGwp($payments));
    }

    public function getTotalGwp($payments = null)
    {
        return $this->getPremium()->sumGwp($this->getMonthsForCancellationCalc($payments));
    }

    public function getRemainingTotalIpt($payments)
    {
        return $this->toTwoDp($this->getTotalIpt() - $this->getTotalIpt($payments));
    }

    public function getTotalIpt($payments = null)
    {
        return $this->getPremium()->sumIpt($this->getMonthsForCancellationCalc($payments));
    }

    public function getRemainingTotalBrokerFee($payments)
    {
        return $this->toTwoDp($this->getTotalBrokerFee() - $this->getTotalBrokerFee($payments));
    }

    public function getTotalBrokerFee($payments = null)
    {
        $includeFinalCommission = false;
        if ($payments) {
            foreach ($payments as $payment) {
                if ($payment->getTotalCommission() == Salva::FINAL_MONTHLY_TOTAL_COMMISSION) {
                    $includeFinalCommission = true;
                }
            }
        }
        $salva = new Salva();

        return $salva->sumBrokerFee($this->getMonthsForCancellationCalc($payments), $includeFinalCommission);
    }

    public function getMonthsForCancellationCalc($payments = null)
    {
        // Cooloff should always return 0
        if ($this->getStatus() == self::STATUS_CANCELLED &&
            $this->getCancelledReason() == self::CANCELLED_COOLOFF) {
            return 0;
        }

        if ($payments === null) {
            if ($this->getStatus() == self::STATUS_CANCELLED) {
                // If we're cancelled, then just use what payments we've received
                return count($this->getPayments());
            }

            // Otherwise entire year
            return 12;
        } else {
            // Payments passed in (for salva date ranges)
            return count($payments);
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

    public function isFinalMonthlyPayment()
    {
        if ($this->getPremiumPlan() != self::PLAN_MONTHLY) {
            return false;
        }

        // If there's 1 payment outstanding
        if ($this->getOutstandingPremium() == $this->getPremiumInstallmentPrice()) {
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

    public function isCancelled()
    {
        return $this->getStatus() == self::STATUS_CANCELLED;
    }

    public function canCancel($reason, $date = null)
    {
        // Doesn't make sense to cancel
        if (in_array($this->getStatus(), [self::STATUS_CANCELLED, self::STATUS_EXPIRED])) {
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
        if ($payment->getAmount() != $this->getPremiumInstallmentPrice()) {
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

    public function shouldExpirePolicy($date = null)
    {
        if (!$this->isValidPolicy()) {
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

        // Max month is 31 days + 30 days cancellation
        return $this->getLastSuccessfulPaymentCredit()->getDate()->diff($date)->days > 61;
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

    public function isValidPolicy($prefix = null)
    {
        if (!$this->isPolicy()) {
            return false;
        }
        if (!$prefix) {
            $prefix = $this->getPolicyNumberPrefix();
        }

        return strpos($this->getPolicyNumber(), $prefix) === 0;
    }

    public function isBillablePolicy()
    {
        // We should only bill policies that are pending, active or unpaid
        // Doesn't make sense to bill expired or cancelled policies
        return in_array($this->getStatus(), [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_UNPAID]);
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
        return $this->getPotValue() == $this->getMaxPot();
    }

    public function getConnectionValues()
    {
        $connectionValues = [];
        if (!$this->isPolicy()) {
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
            'start_date' => $startDate ? $startDate->format(\DateTime::ATOM) : null,
            'end_date' => $firstCliffDate ?
                $firstCliffDate->format(\DateTime::ATOM) :
                null,
            'value' => $this->getTotalConnectionValue($startDate),
            'teaser' => 'until the Ideal Connection Time expires',
            // @codingStandardsIgnoreStart
            'description' => 'For the best chance of filling your Reward Pot we recommend making all your connections in the first 2 weeks!',
            // @codingStandardsIgnoreEnd
        ];

        $connectionValues[] = [
            'start_date' => $firstCliffDate ?
                $firstCliffDate->format(\DateTime::ATOM) :
                null,
            'end_date' => $secondCliffDate ?
                $secondCliffDate->format(\DateTime::ATOM) :
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
                $secondCliffDate->format(\DateTime::ATOM) :
                null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format(\DateTime::ATOM) : null,
            'value' => $this->getTotalConnectionValue($afterSecondCliffDate),
            'teaser' => '',
            'description' => '',
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
        ];
    }
}
