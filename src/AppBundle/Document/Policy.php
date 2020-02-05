<?php

namespace AppBundle\Document;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Invitation\AppNativeShareInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Note\CallNote;
use AppBundle\Document\Note\Note;
use AppBundle\Document\Participation;
use AppBundle\Document\Note\StandardNote;
use AppBundle\Document\Payment\BacsIndemnityPayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\ChargebackPayment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Exception\DuplicatePaymentException;
use AppBundle\Service\InvitationService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Doctrine\ODM\MongoDB\PersistentCollection;
use Gedmo\Mapping\Annotation as Gedmo;
use AppBundle\Document\File\S3File;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Document\File\PolicyTermsFile;
use AppBundle\Document\File\PolicyScheduleFile;
use AppBundle\Document\Payment\PolicyDiscountPayment;
use AppBundle\Document\Payment\PotRewardPayment;
use AppBundle\Document\Payment\SoSurePotRewardPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\DebtCollectionPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Exception\ClaimException;
use AppBundle\Annotation\DataChange;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PolicyRepository")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("policy_type")
 * @MongoDB\DiscriminatorMap({
 *      Policy::TYPE_SALVA_PHONE="SalvaPhonePolicy",
 *      Policy::TYPE_HELVETIA_PHONE="HelvetiaPhonePolicy"
 * })
 * @MongoDB\Index(keys={"policyNumber"="asc","end"="asc"},
 *     unique="false", sparse="true")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
abstract class Policy
{
    use ArrayToApiArrayTrait;
    use CurrencyTrait;
    use DateTrait;

    const TYPE_SALVA_PHONE = 'salva-phone';
    const TYPE_HELVETIA_PHONE = 'helvetia-phone';

    const ADJUST_TIMEZONE = false;
    const RENEWAL_DAYS = 21;
    const TIMEZONE = "Europe/London";

    const RISK_LEVEL_HIGH = 'high';
    const RISK_LEVEL_MEDIUM = 'medium';
    const RISK_LEVEL_LOW = 'low';

    const RISK_CONNECTED_POT_ZERO = 'connected pot £0';
    const RISK_CONNECTED_SELF_CLAIM = 'claim on policy';
    const RISK_CONNECTED_RECENT_NETWORK_CLAIM = 'connected w/recent claim';
    const RISK_CONNECTED_ESTABLISHED_NETWORK_CLAIM = 'connected w/established claim (30+ days)';
    const RISK_CONNECTED_NO_CLAIM = 'connected w/no claim';
    const RISK_NOT_CONNECTED_NEW_POLICY = 'not connected w/new policy';
    const RISK_NOT_CONNECTED_ESTABLISHED_POLICY = 'not connected w/established policy (30+ days)';
    const RISK_PENDING_CANCELLATION_POLICY = 'policy is pending cancellation';
    const RISK_RENEWED_NO_PREVIOUS_CLAIM = 'policy was renewed with no previous claim';

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED_CLAIMABLE = 'expired-claimable';
    const STATUS_EXPIRED_WAIT_CLAIM = 'expired-wait-claim';
    const STATUS_EXPIRED = 'expired';
    const STATUS_UNPAID = 'unpaid';
    const STATUS_MULTIPAY_REQUESTED = 'multipay-requested';
    const STATUS_MULTIPAY_REJECTED = 'multipay-rejected';
    const STATUS_RENEWAL = 'renewal';
    const STATUS_PENDING_RENEWAL = 'pending-renewal';
    const STATUS_DECLINED_RENEWAL = 'declined-renewal';
    const STATUS_UNRENEWED = 'unrenewed';
    const STATUS_PICSURE_REQUIRED = 'picsure-required';

    const CANCELLED_UNPAID = 'unpaid';
    const CANCELLED_ACTUAL_FRAUD = 'actual-fraud';
    const CANCELLED_SUSPECTED_FRAUD = 'suspected-fraud';
    const CANCELLED_USER_REQUESTED = 'user-requested';
    const CANCELLED_COOLOFF = 'cooloff';
    const CANCELLED_BADRISK = 'badrisk';
    const CANCELLED_DISPOSSESSION = 'dispossession';
    const CANCELLED_WRECKAGE = 'wreckage';
    const CANCELLED_UPGRADE = 'upgrade';
    const CANCELLED_PICSURE_REQUIRED_EXPIRED = 'picsure-required-expired';

    const CANCELLED_REASONS = [
        self::CANCELLED_UNPAID,
        self::CANCELLED_ACTUAL_FRAUD,
        self::CANCELLED_SUSPECTED_FRAUD,
        self::CANCELLED_USER_REQUESTED,
        self::CANCELLED_COOLOFF,
        self::CANCELLED_BADRISK,
        self::CANCELLED_DISPOSSESSION,
        self::CANCELLED_WRECKAGE,
        self::CANCELLED_UPGRADE,
        self::CANCELLED_PICSURE_REQUIRED_EXPIRED
    ];

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

    const DEBT_COLLECTOR_WISE = 'wise';

    const METRIC_ACTIVATION = 'activation';
    const METRIC_HARD_ACTIVATION = 'hard-activation';
    const METRIC_RENEWAL = 'renewal';

    const UNPAID_BACS_MANDATE_PENDING = 'unpaid_bacs_mandate_pending';
    const UNPAID_BACS_MANDATE_INVALID = 'unpaid_bacs_mandate_invalid';
    const UNPAID_BACS_PAYMENT_PENDING = 'unpaid_bacs_payment_pending';
    const UNPAID_BACS_PAYMENT_FAILED = 'unpaid_bacs_payment_failed';
    const UNPAID_BACS_PAYMENT_MISSING = 'unpaid_bacs_payment_missing';
    const UNPAID_BACS_UNKNOWN = 'unpaid_bacs_unknown';
    const UNPAID_CARD_EXPIRED = 'unpaid_card_expired';
    const UNPAID_CARD_PAYMENT_FAILED = 'unpaid_card_payment_failed';
    const UNPAID_CARD_PAYMENT_MISSING = 'unpaid_card_payment_missing';
    const UNPAID_CARD_UNKNOWN = 'unpaid_card_unknown';
    const UNPAID_PAYMENT_METHOD_MISSING = 'unpaid_payment_method_missing';
    const UNPAID_UNKNOWN = 'unpaid_unknown';
    const UNPAID_PAID = 'unpaid_paid';

    public static $unpaidReasons = [
        self::UNPAID_BACS_MANDATE_PENDING,
        self::UNPAID_BACS_MANDATE_INVALID,
        self::UNPAID_BACS_PAYMENT_PENDING,
        self::UNPAID_BACS_PAYMENT_FAILED,
        self::UNPAID_BACS_PAYMENT_MISSING,
        self::UNPAID_CARD_EXPIRED,
        self::UNPAID_CARD_PAYMENT_FAILED,
        self::UNPAID_CARD_PAYMENT_MISSING,
        self::UNPAID_PAID,
    ];

    // coolooff reasons
    const COOLOFF_REASON_DAMAGED ='damaged';
    const COOLOFF_REASON_COST = 'cost';
    const COOLOFF_REASON_ELSEWHERE = 'elsewhere';
    const COOLOFF_REASON_EXISTING = 'existing';
    const COOLOFF_REASON_UNDESIRED = 'undesidered';
    const COOLOFF_REASON_TECHNICAL = 'technical';
    const COOLOFF_REASON_PICSURE = 'pic-sure';
    const COOLOFF_REASON_UNKNOWN = 'unknown';

    public static $cooloffReasons = [
        self::COOLOFF_REASON_DAMAGED,
        self::COOLOFF_REASON_COST,
        self::COOLOFF_REASON_ELSEWHERE,
        self::COOLOFF_REASON_EXISTING,
        self::COOLOFF_REASON_UNDESIRED,
        self::COOLOFF_REASON_TECHNICAL,
        self::COOLOFF_REASON_PICSURE,
        self::COOLOFF_REASON_UNKNOWN,
    ];

    public static $riskLevels = [
        self::RISK_CONNECTED_POT_ZERO => self::RISK_LEVEL_HIGH,
        self::RISK_CONNECTED_SELF_CLAIM => self::RISK_LEVEL_HIGH,
        self::RISK_CONNECTED_RECENT_NETWORK_CLAIM => self::RISK_LEVEL_MEDIUM,
        self::RISK_CONNECTED_ESTABLISHED_NETWORK_CLAIM => self::RISK_LEVEL_LOW,
        self::RISK_CONNECTED_NO_CLAIM => self::RISK_LEVEL_LOW,
        self::RISK_NOT_CONNECTED_NEW_POLICY => self::RISK_LEVEL_HIGH,
        self::RISK_NOT_CONNECTED_ESTABLISHED_POLICY => self::RISK_LEVEL_MEDIUM,
        self::RISK_PENDING_CANCELLATION_POLICY => self::RISK_LEVEL_HIGH,
        self::RISK_RENEWED_NO_PREVIOUS_CLAIM => self::RISK_LEVEL_LOW,
    ];

    public static $expirationStatuses = [
        Policy::STATUS_EXPIRED,
        Policy::STATUS_EXPIRED_CLAIMABLE,
        Policy::STATUS_EXPIRED_WAIT_CLAIM
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Payment\Payment",
     *     mappedBy="policy", cascade={"persist"})
     * @var ArrayCollection
     */
    protected $payments;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="policies")
     * @Gedmo\Versioned
     * @var User
     */
    protected $user;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AffiliateCompany", inversedBy="confirmedPolicies")
     */
    protected $affiliate;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy", inversedBy="previousPolicy")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $nextPolicy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Policy", inversedBy="nextPolicy")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $previousPolicy;

    /**
     * Secondary access to policy - not insured but allowed to access
     *
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="namedPolicies")
     * @Gedmo\Versioned
     */
    protected $namedUser;

    /**
     * @MongoDB\ReferenceOne(targetDocument="CustomerCompany", inversedBy="policies")
     * @Gedmo\Versioned
     */
    protected $company;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="payerPolicies")
     * @Gedmo\Versioned
     */
    protected $payer;

    /**
     * @Assert\Choice({"pending", "active", "cancelled", "expired", "expired-claimable", "expired-wait-claim",
     *                  "unpaid", "multipay-requested", "multipay-rejected", "renewal",
     *                  "pending-renewal", "declined-renewal", "unrenewed", "picsure-required"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @DataChange(categories="hubspot")
     */
    protected $status;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $statusUpdated;

    /**
     * @Assert\Choice({"wise"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $debtCollector;

    /**
     * @Assert\Choice({
     *  "unpaid", "actual-fraud", "suspected-fraud", "user-requested",
     *  "cooloff", "badrisk", "dispossession", "wreckage", "upgrade", "picsure-required-expired"
     * }, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $cancelledReason;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     * @var boolean
     */
    protected $cancelledFullRefund;

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
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\Invitation\Invitation",
     *  mappedBy="policy",
     *  cascade={"persist"}
     * )
     * @var ArrayCollection
     */
    protected $invitations;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\PolicyTerms")
     * @Gedmo\Versioned
     * @var PolicyTerms
     */
    protected $policyTerms;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Cashback", cascade={"persist"}, orphanRemoval="true")
     * @Gedmo\Versioned
     * @var Cashback|null
     */
    protected $cashback;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Connection\Connection", cascade={"persist"})
     */
    protected $connections = array();

    /**
     * @AppAssert\RenewalConnectionsAmount()
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Connection\RenewalConnection", cascade={"persist"})
     */
    protected $renewalConnections = array();

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\Connection\Connection",
     *  mappedBy="linkedPolicy",
     *  cascade={"persist"}
     * )
     */
    protected $acceptedConnections;

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\Connection\Connection",
     *  mappedBy="linkedPolicyRenewal",
     *  cascade={"persist"}
     * )
     */
    protected $acceptedConnectionsRenewal;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Claim",
     *  mappedBy="policy",
     *  cascade={"persist"})
     */
    protected $claims;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Claim",
     *  mappedBy="linkedPolicy",
     *  cascade={"persist"})
     */
    protected $linkedClaims;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $issueDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @MongoDB\Index(unique=false, sparse=true)
     * @DataChange(categories="hubspot")
     */
    protected $start;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $billing;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @MongoDB\Index(unique=false, sparse=true)
     * @DataChange(categories="hubspot")
     */
    protected $end;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $staticEnd;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $pendingCancellation;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $renewalExpiration;

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
     * @var Premium
     */
    protected $premium;

    /**
     * @MongoDB\EmbedOne(targetDocument="IdentityLog")
     * @Gedmo\Versioned
     * @var IdentityLog
     */
    protected $identityLog;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\ScheduledPayment", cascade={"persist"})
     */
    protected $scheduledPayments;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
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
     * @MongoDB\Field(type="hash")
     */
    protected $notes = array();

    /**
     * @MongoDB\EmbedMany(targetDocument="AppBundle\Document\Note\Note")
     */
    protected $notesList = array();

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $viewedCancellationPage;

    /**
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $policyDiscountPresent;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $requestedCancellation;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $requestedCancellationReason;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="256")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $requestedCancellationReasonOther;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $visitedWelcomePage;

    /**
     * @MongoDB\Field(type="collection")
     * @MongoDB\Index(unique=false)
     * @Gedmo\Versioned
     */
    protected $metrics;

    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="10", max="10")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $tasteCard;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Participation")
     */
    protected $participations = array();

    /**
     * Vid of the policy's representation as a deal on hubspot. Note that hubspot deals can be manually deleted, so
     * this value is not a guarantee that there is currently a deal on hubspot representing this policy.
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $hubspotId;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\PaymentMethod\PaymentMethod")
     * @Gedmo\Versioned
     * @var PaymentMethod
     */
    protected $paymentMethod;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $dontCancelIfUnpaid;

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
        $this->payments = new \Doctrine\Common\Collections\ArrayCollection();
        $this->invitations = new \Doctrine\Common\Collections\ArrayCollection();
        $this->claims = new \Doctrine\Common\Collections\ArrayCollection();
        $this->linkedClaims = new \Doctrine\Common\Collections\ArrayCollection();
        $this->acceptedConnections = new \Doctrine\Common\Collections\ArrayCollection();
        $this->acceptedConnectionsRenewal = new \Doctrine\Common\Collections\ArrayCollection();
        $this->scheduledPayments = new \Doctrine\Common\Collections\ArrayCollection();
        $this->notesList = new \Doctrine\Common\Collections\ArrayCollection();
        $this->participations = new \Doctrine\Common\Collections\ArrayCollection();
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
     *
     * @return array
     */
    public function getPayments(\DateTime $date = null)
    {
        $payments = [];
        foreach ($this->payments as $payment) {
            $excludeDate = false;
            if ($date && $payment->getDate() > $date) {
                $excludeDate = true;
            }
            if ($payment->isStandardPayment() && !$excludeDate) {
                $payments[] = $payment;
            }
        }

        return $payments;
    }

    public function getSortedPayments($mostRecent = true, \DateTime $date = null)
    {
        $payments = $this->getPayments($date);

        if ($mostRecent) {
            // sort more recent to older
            usort($payments, function ($a, $b) {
                return $a->getDate() < $b->getDate();
            });
        } else {
            // sort older to more recent
            usort($payments, function ($a, $b) {
                return $a->getDate() > $b->getDate();
            });
        }
        //\Doctrine\Common\Util\Debug::dump($payments, 3);

        return $payments;
    }

    /**
     * @return ArrayCollection
     */
    public function getAllPayments()
    {
        return $this->payments;
    }

    public function addPayment($payment)
    {
        $payment->setPolicy($this);
        if (!$payment instanceof DebtCollectionPayment) {
            $payment->calculateSplit();
        }

        // perhaps an issue with cascade persist
        // seems to have no ill effects and resolves the issue
        if ($this->payments->contains($payment)) {
            throw new DuplicatePaymentException(sprintf(
                'duplicate payment %s',
                $payment->getId()
            ));
        }

        $this->payments->add($payment);
        // Reporting is much much easier if we have a flag on the record rather than having to traverse payments
        if ($payment instanceof PolicyDiscountPayment) {
            $this->setPolicyDiscountPresent(true);
        }
    }

    public function hasPolicyDiscountPresent()
    {
        return $this->policyDiscountPresent;
    }

    public function setPolicyDiscountPresent($policyDiscountPresent)
    {
        $this->policyDiscountPresent = $policyDiscountPresent;
    }

    public function getPaymentsByType($type)
    {
        return $this->getPaymentsByTypes([$type]);
    }

    public function getPaymentsByTypes($types)
    {
        $payments = $this->getAllPayments();
        if (is_object($payments)) {
            $payments = $payments->toArray();
        }
        if (!$payments) {
            return [];
        }

        return array_filter($payments, function ($payment) use ($types) {
            foreach ($types as $type) {
                if ($payment instanceof $type) {
                    return true;
                }
            }

            return false;
        });
    }

    public function getPaymentsExceptTypes($types)
    {
        $payments = $this->getAllPayments();
        if (is_object($payments)) {
            $payments = $payments->toArray();
        }
        if (!$payments) {
            return [];
        }

        return array_filter($payments, function ($payment) use ($types) {
            foreach ($types as $type) {
                if ($payment instanceof $type) {
                    return false;
                }
            }

            return true;
        });
    }

    public function getPaymentByType($type)
    {
        $payments = $this->getPaymentsByType($type);
        if (!$payments || count($payments) == 0) {
            return null;
        }

        return array_values($payments)[0];
    }

    public function hasAdjustedRewardPotPayment()
    {
        $promoPotRewards = $this->getPaymentsByType(SoSurePotRewardPayment::class);
        $potRewards = $this->getPaymentsByType(PotRewardPayment::class);

        return count($promoPotRewards) > 1 || count($potRewards) > 1;
    }

    public function getAdjustedRewardPotPaymentAmount()
    {
        $promoPotRewards = $this->getPaymentsByType(SoSurePotRewardPayment::class);
        $potRewards = $this->getPaymentsByType(PotRewardPayment::class);

        return Payment::sumPayments($potRewards, false)['total'] +
            Payment::sumPayments($promoPotRewards, false)['total'];
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
            /** @var Payment $payment */
            return $payment->isSuccess();
        });
    }

    /**
     * Successful Payments
     */
    public function getSuccessfulUserPayments()
    {
        $payments = $this->getPayments();
        if (is_object($payments)) {
            $payments = $payments->toArray();
        }
        if (!$this->getPayments()) {
            return [];
        }

        return array_filter($payments, function (Payment $payment) {
            return $payment->isSuccess() && $payment->isUserPayment();
        });
    }

    public function getSuccessfulStandardPayments()
    {
        $payments = $this->getPayments();
        if (is_object($payments)) {
            $payments = $payments->toArray();
        }
        if (!$this->getPayments()) {
            return [];
        }

        return array_filter($payments, function (Payment $payment) {
            return $payment->isSuccess() && $payment->isStandardPayment();
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
            return $payment->getAmount() > 0 && !$payment instanceof SoSurePayment &&
            !$payment instanceof PolicyDiscountPayment;
        });
    }

    /**
     * Gives the set of dates that the policy's premium schedule should consist of.
     * @return array|null of the dates, and null if there is simply no schedule at all because the policy is not valid.
     */
    public function getInvoiceSchedule()
    {
        if (!$this->isPolicy()) {
            return null;
        }
        $invoiceDates = [];
        $invoiceDate = clone $this->billing;
        for ($i = 0; $i < $this->getPremiumInstallments(); $i++) {
            if ($invoiceDate <= $this->getEnd()) {
                $invoiceDates[] = clone $invoiceDate;
                $invoiceDate = $invoiceDate->add(new \DateInterval('P1M'));
            }
        }
        return $invoiceDates;
    }

    public function getInvoiceAmountToDate(\DateTime $date = null)
    {
        if (!$date) {
            $date = $this->now();
        }

        if (!$this->isActive() && $this->hasMonetaryClaimed(true)) {
            return $this->getYearlyPremiumPrice();
        }

        if ($this->getStatus() == Policy::STATUS_CANCELLED) {
            return $this->getProratedPremium($this->getEnd());
        }

        $invoiceSchedule = $this->getInvoiceSchedule();
        if (!$invoiceSchedule) {
            return null;
        }
        $total = 0;
        foreach ($invoiceSchedule as $invoiceDate) {
            if ($invoiceDate < $date) {
                $total += $this->getPremiumInstallmentPrice();
            }
        }

        return $total;
    }

    public function getInvoiceAmountTotal()
    {
        if (!$this->isActive() && $this->hasMonetaryClaimed(true)) {
            return $this->getYearlyPremiumPrice();
        }

        if ($this->getStatus() == Policy::STATUS_CANCELLED) {
            return $this->getProratedPremium($this->getEnd());
        }

        $invoiceSchedule = $this->getInvoiceSchedule();
        if (!$invoiceSchedule) {
            return null;
        }
        $total = 0;
        foreach ($invoiceSchedule as $invoiceDate) {
            $total += $this->getPremiumInstallmentPrice();
        }

        return $total;
    }

    public function getCurrentInvoiceBalance(\DateTime $date = null)
    {
        return $this->getPremiumPaid(null, $date) - $this->getInvoiceAmountToDate($date);
    }

    /**
     * Gives you the payment type of the last successful user credit payment, or failing that, the payment type of the
     * policy as a whole, and failing that, null.
     * @return string|null the payment type of the policy or null if there is no payment type information at all.
     */
    public function getUsedPaymentType()
    {
        $payment = $this->getLastSuccessfulUserPaymentCredit();
        $method = $this->getPolicyOrUserPaymentMethod();
        return $payment ? $payment->getType() : ($method ? $method->getType() : null);
    }

    /**
     * Payments filtered by credits (pos amount)
     */
    public function getSuccessfulUserPaymentCredits()
    {
        return array_filter($this->getSuccessfulUserPayments(), function ($payment) {
            return $payment->getAmount() > 0;
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

    /**
     * @return Payment|null
     */
    public function getFirstSuccessfulUserPaymentCredit()
    {
        $payments = $this->getSuccessfulUserPaymentCredits();
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

    /**
     * Gets the most recent scheduled payment if it is reverted.
     * @return ScheduledPayment|null the scheduled payment or if there is not one of those you get null.
     */
    public function getLastRevertedScheduledPayment()
    {
        $scheduledPayments = $this->getScheduledPayments()->toArray();
        $pastPayments = array_filter($scheduledPayments, function ($scheduledPayment) {
            return !in_array($scheduledPayment->getStatus(), [
                ScheduledPayment::STATUS_SCHEDULED,
                ScheduledPayment::STATUS_PENDING
            ]);
        });
        if (count($pastPayments) == 0) {
            return null;
        }
        usort($pastPayments, function ($a, $b) {
            return $a->getScheduled() < $b->getScheduled();
        });
        $lastPayment = $pastPayments[0];
        if ($lastPayment->getStatus() == ScheduledPayment::STATUS_REVERTED) {
            return $lastPayment;
        } else {
            return null;
        }
    }

    /**
     * Gives you the policy's successful scheduled payment with the greatest schedule date even if this date is greater
     * than the current time.
     * @return ScheduledPayment|null the latest successful scheduled payment or null if there are no successful
     *                               scheduled payments at all.
     */
    public function getLatestSuccessfulScheduledPayment()
    {
        $latest = null;
        foreach ($this->getScheduledPayments() as $scheduledPayment) {
            if ($scheduledPayment->getStatus() != ScheduledPayment::STATUS_SUCCESS) {
                continue;
            }
            if (!$latest || $latest->getScheduled() < $scheduledPayment->getScheduled()) {
                $latest = $scheduledPayment;
            }
        }
        return $latest;
    }

    /**
     * @return Payment|null
     */
    public function getLastSuccessfulUserPaymentCredit()
    {
        $payments = $this->getSuccessfulUserPaymentCredits();
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

    /**
     * @return Payment|null
     */
    public function getLastPaymentCredit($excludeMissingStatus = true)
    {
        $payments = $this->getPaymentCredits();
        if (count($payments) == 0) {
            return null;
        }

        // TODO: Consider if this should be a filter by payment type instead of missing status (or both)
        // originally added as with bacs, there was an initial judo payment added with empty status
        // which needed to be excluded
        if ($excludeMissingStatus) {
            $payments = array_filter($payments, function ($payment) {
                if ($payment instanceof JudoPayment) {
                    /** @var JudoPayment $payment */
                    return $payment->getResult() !== null;
                } elseif ($payment instanceof BacsPayment) {
                    /** @var BacsPayment $payment */
                    return $payment->getStatus() !== null;
                } elseif ($payment instanceof CheckoutPayment) {
                    /** @var CheckoutPayment $payment */
                    return $payment->getResult() !== null;
                }
            });
        }

        // sort more recent to older
        usort($payments, function ($a, $b) {
            return $a->getDate() < $b->getDate();
        });

        return $payments[0];
    }

    /**
     * @return Payment|null
     */
    public function getLastPaymentDebit()
    {
        $payments = $this->getPaymentDebits();
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

    public function getPaymentDebits()
    {
        $payments = array_filter($this->getAllPayments()->toArray(), function ($payment) {
                return $payment->getAmount() <= 0 && !$payment instanceof SoSurePayment;
        });

        return $payments;
    }

    public function getPaymentCredits()
    {
        $payments = array_filter($this->getAllPayments()->toArray(), function ($payment) {
            return $payment->getAmount() > 0 && !$payment instanceof SoSurePayment;
        });

        return $payments;
    }

    public function getPurchaseSdk()
    {
        // Going forward there should always be a sdk associated with the identity log, however previously, not the case
        if ($this->getIdentityLog() && $this->getIdentityLog()->getSdk()) {
            return $this->getIdentityLog()->getSdk();
        }

        $source = $this->getFirstSuccessfulUserPaymentCredit();
        if ($source) {
            switch ($source) {
                case Payment::SOURCE_WEB:
                case Payment::SOURCE_WEB_API:
                    return IdentityLog::SDK_WEB;
                case Payment::SOURCE_TOKEN:
                case Payment::SOURCE_SYSTEM:
                case Payment::SOURCE_BACS:
                    // historically, bacs was only implemented on the web, so anything related to bacs payments
                    // should be web (new sdk identity log should resolve future requests better)
                    // as bacs was usually associated with token payments, system & token should be assumed to be bacs
                    return IdentityLog::SDK_WEB;
                case Payment::SOURCE_MOBILE:
                    if ($this instanceof PhonePolicy) {
                        /** @var Phone $phone */
                        $phone = $this->getPhone();
                        if ($phone->isApple()) {
                            return IdentityLog::SDK_IOS;
                        } else {
                            return IdentityLog::SDK_ANDROID;
                        }
                    }

                    return IdentityLog::SDK_UNKNOWN;
                case Payment::SOURCE_APPLE_PAY:
                    return IdentityLog::SDK_IOS;
                case Payment::SOURCE_ANDROID_PAY:
                    return IdentityLog::SDK_ANDROID;
                default:
                    return IdentityLog::SDK_UNKNOWN;
            }
        }

        return IdentityLog::SDK_UNKNOWN;
    }

    /**
     * @return Cashback|null
     */
    public function getCashback()
    {
        return $this->cashback;
    }

    public function hasCashback()
    {
        return $this->getCashback() != null;
    }

    public function setCashback($cashback)
    {
        $this->cashback = $cashback;
        if ($cashback) {
            $cashback->setPolicy($this);
        }
    }

    public function clearCashback()
    {
        if ($this->cashback) {
            $this->cashback->setPolicy(null);
        }
        $this->cashback = null;
    }

    /**
     * @return User
     */
    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function getAffiliate()
    {
        return $this->affiliate;
    }

    public function setAffiliate(AffiliateCompany $affiliate)
    {
        $this->affiliate = $affiliate;
    }

    /**
     * @return Policy
     */
    public function getPreviousPolicy()
    {
        return $this->previousPolicy;
    }

    public function hasPreviousPolicy()
    {
        return $this->getPreviousPolicy() != null;
    }

    /**
     * @return Policy
     */
    public function getNextPolicy()
    {
        return $this->nextPolicy;
    }

    public function hasNextPolicy()
    {
        return $this->getNextPolicy() != null;
    }

    public function link(Policy $newPolicy)
    {
        $newPolicy->previousPolicy = $this;
        $this->nextPolicy = $newPolicy;
    }

    public function getNamedUser()
    {
        return $this->namedUser;
    }

    public function setNamedUser(User $namedUser)
    {
        $this->namedUser = $namedUser;
    }

    public function getPayer()
    {
        return $this->payer;
    }

    public function getCompany()
    {
        return $this->company;
    }

    public function setCompany(CustomerCompany $company)
    {
        $this->company = $company;
    }

    public function setPayer(User $user)
    {
        $this->payer = $user;
    }

    public function getPayerOrUser()
    {
        return $this->getPayer() ? $this->getPayer() : $this->getUser();
    }

    public function isDifferentPayer()
    {
        if ($this->getPayer() && $this->getPayer()->getId() != $this->getUser()->getId()) {
            return true;
        }

        return false;
    }

    public function getIssueDate()
    {
        if (!$this->issueDate) {
            return $this->getStart();
        }

        return $this->issueDate;
    }

    public function setIssueDate(\DateTime $issueDate = null)
    {
        $this->issueDate = $issueDate;
    }

    public function getStart()
    {
        if ($this->start) {
            if (self::ADJUST_TIMEZONE) {
                $this->start->setTimezone(new \DateTimeZone(self::TIMEZONE));
            } elseif ($this->start->format('H') == 23) {
                $this->start->setTimezone(new \DateTimeZone(self::TIMEZONE));
            }
        }
        return $this->start;
    }

    public function getStartForBilling()
    {
        if ($this->getStart()) {
            return $this->adjustDayForBilling($this->getStart());
        } else {
            return null;
        }
    }

    public function setStart(\DateTime $start)
    {
        $this->start = $start;
    }

    public function getDebtCollector()
    {
        return $this->debtCollector;
    }

    public function setDebtCollector($debtCollector)
    {
        $this->debtCollector = $debtCollector;
    }

    public function getBilling()
    {
        if ($this->billing) {
            if (self::ADJUST_TIMEZONE) {
                $this->billing->setTimezone(new \DateTimeZone(self::TIMEZONE));
            }
            return $this->billing;
        } else {
            return $this->getStartForBilling();
        }
    }

    public function getBillingDay()
    {
        return $this->getBilling() ? $this->getBilling()->format('j') : null;
    }

    public function setBilling(\DateTime $billing, \DateTime $changeDate = null)
    {
        // Only if changing billing date - allow the initial setting if unpaid
        if ($this->billing && $this->billing != $billing && !$this->isPolicyPaidToDate($changeDate)) {
            throw new \Exception('Unable to changing billing date unless policy is paid to date');
        }
        /**
         * Billing should always be at 3am now, so when we set it we need to ensure that it is.
         */
        $billing->setTimezone(SoSure::getSoSureTimezone());
        $billing->setTime(3, 0);
        $this->billing = $billing;
    }

    /**
     * Sets billing without validation.
     * @param \DateTime $billing is the billing date to set.
     */
    public function setBillingForce(\DateTime $billing)
    {
        $this->billing = $billing;
    }

    public function getEnd()
    {
        if ($this->end) {
            if (self::ADJUST_TIMEZONE) {
                $this->end->setTimezone(new \DateTimeZone(self::TIMEZONE));
            }
        }
        return $this->end;
    }

    public function setEnd(\DateTime $end = null)
    {
        $this->end = $end;
    }

    public function getStaticEnd()
    {
        if ($this->staticEnd) {
            if (self::ADJUST_TIMEZONE) {
                $this->staticEnd->setTimezone(new \DateTimeZone(self::TIMEZONE));
            }
        }
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

    public function getRenewalExpiration()
    {
        return $this->renewalExpiration;
    }

    public function setRenewalExpiration(\DateTime $renewalExpiration = null)
    {
        $this->renewalExpiration = $renewalExpiration;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function getApiStatus()
    {
        if ($this->getStatus() == self::STATUS_EXPIRED_WAIT_CLAIM) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }

    public function setStatus($status)
    {
        if ($this->getStatus() == Policy::STATUS_PICSURE_REQUIRED && $status == Policy::STATUS_UNPAID) {
            throw new \InvalidArgumentException(sprintf(
                "Trying to make picsure-required policy unpaid which is NOT allowed.\npolicyId: %s.",
                $this->getId()
            ));
        }
        if ($status != $this->status) {
            $this->setStatusUpdated(\DateTime::createFromFormat('U', time()));
        }
        $this->status = $status;
    }

    public function setStatusUpdated(\DateTime $statusUpdated = null)
    {
        $this->statusUpdated = $statusUpdated;
    }

    public function getStatusUpdated()
    {
        return $this->statusUpdated;
    }

    public function getCancelledReason()
    {
        return $this->cancelledReason;
    }

    public function setCancelledReason($cancelledReason)
    {
        $this->cancelledReason = $cancelledReason;
    }

    public function isCancelledFullRefund()
    {
        return $this->cancelledFullRefund;
    }

    public function setCancelledFullRefund($cancelledFullRefund)
    {
        $this->cancelledFullRefund = $cancelledFullRefund;
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

    public function addAcceptedConnection(Connection $connection)
    {
        $this->acceptedConnections[] = $connection;
    }

    public function getAcceptedConnections()
    {
        return $this->acceptedConnections;
    }

    public function addAcceptedConnectionRenewal(Connection $connection)
    {
        $this->acceptedConnectionsRenewal[] = $connection;
    }

    public function getAcceptedConnectionsRenewal()
    {
        return $this->acceptedConnectionsRenewal;
    }

    public function getAcceptedConnectionsRenewalIds()
    {
        $ids = [];
        foreach ($this->getAcceptedConnectionsRenewal() as $connection) {
            $ids[] = $connection->getId();
        }

        return $ids;
    }

    public function getMetrics()
    {
        return $this->metrics;
    }

    public function setMetrics($metrics)
    {
        $this->metrics = $metrics;
    }

    public function addMetric($metric)
    {
        $this->metrics[] = $metric;
    }

    public function getTasteCard()
    {
        return $this->tasteCard;
    }

    public function setTasteCard($tasteCard)
    {
        $this->tasteCard = $tasteCard;
    }

    public function getParticipations()
    {
        return $this->participations;
    }

    public function addParticipation(Participation $participation)
    {
        $participation->setPolicy($this);
        $this->participations[] = $participation;
    }

    public function getHubspotId()
    {
        return $this->hubspotId;
    }

    public function setHubspotId($hubspotId)
    {
        $this->hubspotId = $hubspotId;
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

    public function getNonRewardConnections()
    {
        $connections = [];
        foreach ($this->getConnections() as $connection) {
            if (!($connection instanceof RewardConnection) || $connection->getLinkedUser()->getIsInfluencer()) {
                $connections[] = $connection;
            }
        }
        return $connections;
    }

    public function isConnected(Policy $policy)
    {
        foreach ($this->getStandardConnections() as $connection) {
            /** @var Connection $connection */
            if ($connection->getLinkedPolicy()->getId() == $policy->getId()) {
                return true;
            } elseif ($connection->getLinkedPolicyRenewal() &&
                $connection->getLinkedPolicyRenewal()->getId() == $policy->getId()) {
                // Not sure if is required or not - there was an issue with Nick's policy missing connections
                // which could be resolved by this
                return true;
            }
        }

        return false;
    }

    /**
     * In the case of multiple policies get the connections where the user is the same
     */
    public function getStandardSelfConnections()
    {
        $connections = [];
        foreach ($this->getStandardConnections() as $connection) {
            if ($connection->getLinkedUser()) {
                if ($connection->getLinkedUser()->getId() == $this->getUser()->getId()) {
                    $connections[] = $connection;
                }
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

    public function addRenewalConnection(RenewalConnection $connection)
    {
        $connection->setSourcePolicy($this);
        $connection->setSourceUser($this->getUser());
        $this->renewalConnections[] = $connection;
    }

    public function getRenewalConnections()
    {
        return $this->renewalConnections;
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

    /**
     * @param bool $requireReplacementImei
     * @return Claim|null
     */
    public function getLatestClaim($requireReplacementImei = false)
    {
        $claims = $this->getClaims();
        if (!is_array($claims)) {
            $claims = $claims->getValues();
        }
        if ($requireReplacementImei) {
            $claims = array_filter($claims, function ($claim) {
                return $claim->getReplacementImei() !== null;
            });
        }
        if (count($claims) == 0) {
            return null;
        }

        // sort most recent to older
        usort($claims, function ($a, $b) {
            return $a->getRecordedDate() < $b->getRecordedDate();
        });

        return $claims[0];
    }

    /**
     * Gives you a list of all of the policy's claims that fall within a given period.
     * @param \DateTime $start is the start of the interval.
     * @param \DateTime $end   is the end of the interval.
     * @return array containing all of the claims that happened in this period.
     */
    public function getClaimsInPeriod(\DateTime $start, \DateTime $end)
    {
        $claims = $this->getClaims();
        $periodClaims = [];
        foreach ($claims as $claim) {
            $date = $claim->getRecordedDate();
            if ($date < $start || $date > $end) {
                $periodClaims[] = $claim;
            }
        }
        return $periodClaims;
    }

    public function getLatestFnolClaim()
    {
        return $this->getLatestClaimByStatus(array(Claim::STATUS_FNOL));
    }

    public function getLatestSubmittedClaim()
    {
        return $this->getLatestClaimByStatus(array(Claim::STATUS_SUBMITTED));
    }

    /**
     * @return Claim|null
     */
    public function getLatestFnolSubmittedClaim()
    {
        return $this->getLatestClaimByStatus(array(Claim::STATUS_FNOL, Claim::STATUS_SUBMITTED));
    }

    public function getLatestInReviewClaim()
    {
        return $this->getLatestClaimByStatus(array(Claim::STATUS_INREVIEW));
    }

    /**
     * @return Claim|null
     */
    public function getLatestFnolSubmittedInReviewClaim()
    {
        return $this->getLatestClaimByStatus(array(
            Claim::STATUS_FNOL,
            Claim::STATUS_SUBMITTED,
            Claim::STATUS_INREVIEW));
    }

    private function getLatestClaimByStatus($status)
    {
        $claims = $this->getClaims();
        if (!is_array($claims)) {
            $claims = $claims->getValues();
        }
        if (count($claims) == 0) {
            return null;
        }

        // sort most recent to older
        usort($claims, function ($a, $b) {
            return $a->getRecordedDate() < $b->getRecordedDate();
        });

        if (in_array($claims[0]->getStatus(), $status)) {
            return $claims[0];
        }
        return null;
    }

    public function addLinkedClaim(Claim $claim)
    {
        $claim->setLinkedPolicy($this);
        $this->linkedClaims[] = $claim;
    }

    public function getLinkedClaims()
    {
        return $this->linkedClaims;
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

    public function hasUnprocessedMonetaryClaim()
    {
        foreach ($this->getClaims() as $claim) {
            /** @var Claim $claim */
            if ($claim->isMonetaryClaim() && !$claim->getProcessed()) {
                return true;
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
        foreach ($this->getLinkedClaims() as $claim) {
            if ($claim->getSuspectedFraud()) {
                return true;
            }
        }

        return false;
    }

    public function getApprovedClaims(
        $includeSettled = true,
        $includeLinkedClaims = false,
        $excludeIgnoreUserDeclined = false
    ) {
        $claims = [];
        foreach ($this->getClaims() as $claim) {
            $addClaim = false;
            if ($claim->getStatus() == Claim::STATUS_APPROVED) {
                $addClaim = true;
            }
            if ($includeSettled && $claim->getStatus() == Claim::STATUS_SETTLED) {
                $addClaim = true;
            }
            if ($excludeIgnoreUserDeclined && $claim->hasIgnoreUserDeclined()) {
                $addClaim = false;
            }
            if ($addClaim) {
                $claims[] = $claim;
            }
        }
        if ($includeLinkedClaims) {
            foreach ($this->getLinkedClaims() as $claim) {
                $addClaim = false;
                if ($claim->getStatus() == Claim::STATUS_APPROVED) {
                    $addClaim = true;
                }
                if ($includeSettled && $claim->getStatus() == Claim::STATUS_SETTLED) {
                    $addClaim = true;
                }
                if ($excludeIgnoreUserDeclined && $claim->hasIgnoreUserDeclined()) {
                    $addClaim = false;
                }
                if ($addClaim) {
                    $claims[] = $claim;
                }
            }
        }

        return $claims;
    }

    public function getWithdrawnDeclinedClaims($includeLinkedClaims = false, $excludeIgnoreUserDeclined = false)
    {
        $claims = [];
        foreach ($this->getClaims() as $claim) {
            $addClaim = false;
            if (in_array($claim->getStatus(), [Claim::STATUS_DECLINED, Claim::STATUS_WITHDRAWN])) {
                $addClaim = true;
            }
            if ($excludeIgnoreUserDeclined && $claim->hasIgnoreUserDeclined()) {
                $addClaim = false;
            }
            if ($addClaim) {
                $claims[] = $claim;
            }
        }
        if ($includeLinkedClaims) {
            foreach ($this->getLinkedClaims() as $claim) {
                $addClaim = false;
                if (in_array($claim->getStatus(), [Claim::STATUS_DECLINED, Claim::STATUS_WITHDRAWN])) {
                    $addClaim = true;
                }
                if ($excludeIgnoreUserDeclined && $claim->hasIgnoreUserDeclined()) {
                    $addClaim = false;
                }
                if ($addClaim) {
                    $claims[] = $claim;
                }
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
            throw new \Exception(sprintf(
                'Max pot value exceeded (%s of %s for %s)',
                $potValue,
                $this->getMaxPot(),
                $this->getId()
            ));
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

    /**
     * Standard Value (e.g. Salva / the non-promo portion of the pot value)
     */
    public function getStandardPotValue()
    {
        return $this->toTwoDp($this->getPotValue() - $this->getPromoPotValue());
    }

    /**
     * @return PolicyTerms
     */
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

    /**
     * @return ArrayCollection
     */
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

    public function addInvitation(Invitation $invitation)
    {
        $invitation->setPolicy($this);
        $this->getUser()->addSentInvitation($invitation);

        $this->invitations[] = $invitation;
    }

    public function setPremium(Premium $premium)
    {
        $this->premium = $premium;
    }

    /**
     * @return Premium
     */
    public function getPremium()
    {
        return $this->premium;
    }

    /**
     * @return IdentityLog
     */
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

    public function getActiveScheduledPayments()
    {
        $payments = $this->getScheduledPayments();
        $active = [];
        foreach ($payments as $payment) {
            if ($payment->getStatus() != ScheduledPayment::STATUS_CANCELLED) {
                $active[] = $payment;
            }
        }
        return $active;
    }

    /**
     * Gets all scheduled refunds in the future.
     * @return array containing all of these refunds.
     */
    public function getScheduledPaymentRefunds()
    {
        $payments = $this->getScheduledPayments();
        $refunds = [];
        foreach ($payments as $payment) {
            if ($payment->getType() == ScheduledPayment::TYPE_REFUND) {
                $refunds[] = $payment;
            }
        }
        return $refunds;
    }

    /**
     * Adds up the total amount of money in scheduled payments.
     * @return float the total amount.
     */
    public function getScheduledPaymentRefundAmount()
    {
        $payments = $this->getScheduledPayments();
        $total = 0;
        foreach ($payments as $payment) {
            if ($payment->getType() == ScheduledPayment::TYPE_REFUND) {
                $total -= $payment->getAmount();
            }
        }
        return $total;
    }

    public function addScheduledPayment(ScheduledPayment $scheduledPayment)
    {
        // For some reason, duplicate scheduled payments occurred in production
        // this pattern is used in other methods to fix issues with tests, so will hopefully work
        if ($this->scheduledPayments->contains($scheduledPayment)) {
            throw new \Exception('duplicate scheduled payment');
        }

        $scheduledPayment->setPolicy($this);
        $this->scheduledPayments[] = $scheduledPayment;
    }

    /**
     * Gets the scheduled payment with status scheduled that has the lowest date.
     * @return ScheduledPayment|null the first scheduled payment or null if there are none.
     */
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

    /**
     * Gets the next upcoming rescheduled scheduled payment.
     * @return ScheduledPayment|null which has been found, or null if no rescheduled scheduled payments are found with
     *                               status scheduled.
     */
    public function getNextRescheduledScheduledPayment()
    {
        $next = null;
        foreach ($this->getScheduledPayments() as $scheduledPayment) {
            if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED &&
                $scheduledPayment->getType() == ScheduledPayment::TYPE_RESCHEDULED &&
                (!$next || $next->getScheduled() > $scheduledPayment->getScheduled())) {
                $next = $scheduledPayment;
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
        if (!in_array($premiumInstallments, [1, 12])) {
            throw new \Exception(sprintf(
                'Only monthly (12) or yearly (1) installments are supported, not %d',
                $premiumInstallments
            ));
        }
        $this->premiumInstallments = $premiumInstallments;
    }

    public function getSCodes()
    {
        return $this->scodes;
    }

    /**
     * Returns the first scode that the policy has.
     * @return SCode|null the first scode or null if there are none.
     */
    public function getFirstScode()
    {
        foreach ($this->scodes as $scode) {
            return $scode;
        }
        return null;
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

    public function setPolicyStatusActiveIfUnpaid()
    {
        if ($this->getStatus() == self::STATUS_UNPAID) {
            $this->setStatus(self::STATUS_ACTIVE);
        }
    }

    public function setPolicyStatusUnpaidIfActive($checkUnpaidStatus = true, \DateTime $date = null)
    {
        if ($this->getStatus() == self::STATUS_ACTIVE) {
            if (!$checkUnpaidStatus || ($checkUnpaidStatus &&
                !$this->isPolicyPaidToDate($date, true, false, true))) {
                $this->setStatus(self::STATUS_UNPAID);
            }
        }
    }

    /**
     * Tells you if this policy is currently active, as in the active, unpaid, or picsure-required status.
     * @return boolean true if the policy is active and false if it is not.
     */
    public function isActive()
    {
        return in_array($this->getStatus(), [
            self::STATUS_PICSURE_REQUIRED,
            self::STATUS_ACTIVE,
            self::STATUS_UNPAID
        ]);
    }

    public function getPolicyFiles()
    {
        return $this->policyFiles;
    }

    public function getPolicyFilesByType($type)
    {
        $files = [];
        foreach ($this->getPolicyFiles() as $file) {
            if ($file instanceof $type) {
                $files[] = $file;
            }
        }

        // sort more recent to older
        usort($files, function ($a, $b) {
            return $a->getCreated() < $b->getCreated();
        });

        return $files;
    }

    public function getPolicyScheduleFiles()
    {
        return $this->getPolicyFilesByType(PolicyScheduleFile::class);
    }

    public function getPolicyTermsFiles()
    {
        return $this->getPolicyFilesByType(PolicyTermsFile::class);
    }

    /**
     * Gives you the most recent policy terms file object that this policy is linked to
     * @return PolicyTermsFile|null the file or null if there are no such files.
     */
    public function getLatestPolicyTermsFile()
    {
        foreach ($this->getPolicyTermsFiles() as $file) {
            return $file;
        }
        return null;
    }

    public function addPolicyFile(S3File $file)
    {
        $this->policyFiles[] = $file;
    }

    public function removePolicyFile(S3File $file)
    {
        $files = [];
        foreach ($this->policyFiles as $policyFile) {
            if ($policyFile != $file) {
                $files[] = $policyFile;
            }
        }
        $this->policyFiles = $files;
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

    public function getNotes()
    {
        return $this->notes;
    }

    public function removeNote($time)
    {
        unset($this->notes[$time]);
    }

    public function getNotesList()
    {
        return $this->notesList;
    }

    public function addNotesList(Note $note)
    {
        $this->notesList[] = $note;
    }

    public function addNoteDetails($notes, User $user = null, $action = null, \DateTime $date = null)
    {
        $note = new StandardNote();
        $note->setNotes($notes);

        if ($user) {
            $note->setUser($user);
        }

        if ($action) {
            $note->setAction($action);
        }

        if ($date) {
            $note->setDate($date);
        }

        $this->addNotesList($note);
    }

    public function getNoteCalledCount(\DateTime $date)
    {
        $notes = $this->getNotesList()->toArray();
        if (count($notes) == 0) {
            return 0;
        }

        $notes = array_filter($notes, function ($note) use ($date) {
            /** @var Note $note */
            return $note->getType() == Note::TYPE_CALL && $note->getDate() >= $date;
        });

        return count($notes);
    }

    public function getLatestNoteByType($type)
    {
        $notes = $this->getNotesList()->toArray();
        $notes = array_filter($notes, function ($note) use ($type) {
            /** @var Note $note */
            return $note->getType() == $type;
        });
        if (count($notes) == 0) {
            return null;
        }

        // sort more recent to older
        usort($notes, function ($a, $b) {
            return $a->getDate() < $b->getDate();
        });

        return $notes[0];
    }

    private function getLatestNotesDate()
    {
        $notes = $this->getNotesList()->toArray();
        if (count($notes) == 0) {
            return null;
        }

        // sort more recent to older
        usort($notes, function ($a, $b) {
            return $a->getDate() < $b->getDate();
        });

        return $notes[0]->getDate();
    }

    public function getLatestNoteTimestampColour()
    {
        $latest = $this->getLatestNotesDate();
        if (!$latest) {
            return 'white';
        }

        $now = \DateTime::createFromFormat('U', time());
        $diff = $now->diff($latest);

        if ($diff->days > 30) {
            return 'red';
        } elseif ($diff->days <= 2) {
            return 'green';
        }

        return 'white';
    }

    public function setId($id)
    {
        if ($this->id) {
            throw new \Exception('Can not reasssign id');
        }

        $this->id = $id;
    }

    public function getPreviousBillingDate($date, $filtered = true)
    {
        $nextBillingDate = $this->getNextBillingDate($date, $filtered);
        $previousBillingDate = clone $nextBillingDate;
        if ($this->getPremiumPlan() == self::PLAN_MONTHLY) {
            $previousBillingDate->sub(new \DateInterval('P1M'));
        } else {
            $previousBillingDate->sub(new \DateInterval('P1Y'));
        }

        // failsafe - billing date can never be, before the start date
        if ($previousBillingDate < $this->getStart()) {
            $previousBillingDate = $this->getStart();
        }

        return $previousBillingDate;
    }

    public function getNextBillingDate($date, $filtered = true)
    {
        $nextDate = new \DateTime('now', new \DateTimeZone(self::TIMEZONE));
        $this->clearTime($nextDate);
        if ($this->getPremiumPlan() == self::PLAN_MONTHLY) {
            $nextDate->setDate($date->format('Y'), $date->format('m'), $this->getBilling()->format('d'));
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

    public function hasPaymentMethod()
    {
        return $this->getPaymentMethod() != null;
    }

    public function hasValidPaymentMethod()
    {
        return $this->hasPaymentMethod() && $this->getPaymentMethod()->isValid();
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

    public function hasPolicyOrUserBacsPaymentMethod()
    {
        // TODO: Eventually remove this method
        return $this->getPolicyOrUserBacsPaymentMethod() instanceof BacsPaymentMethod;
    }

    public function getPolicyOrUserBacsPaymentMethod()
    {
        // TODO: Eventually remove this method
        return $this->getBacsPaymentMethod();
    }

    public function getPolicyOrUserBacsBankAccount()
    {
        // TODO: Eventually remove this method
        return $this->getBacsBankAccount();
    }

    public function hasPolicyOrUserPaymentMethod()
    {
        // TODO: Eventually remove this method
        return $this->hasPaymentMethod();
    }

    public function hasPolicyOrUserValidPaymentMethod()
    {
        // TODO: Eventually remove this method
        return $this->hasValidPaymentMethod();
    }

    public function hasPolicyOrPayerOrUserValidPaymentMethod()
    {
        // TODO: Eventually remove this method
        return $this->hasValidPaymentMethod();
    }

    public function getPolicyOrUserPaymentMethod()
    {
        // TODO: Eventually remove this method
        return $this->getPaymentMethod();
    }

    public function getPolicyOrPayerOrUserPaymentMethod()
    {
        // TODO: Eventually remove this method
        return $this->getPaymentMethod();
    }

    /**
     * Returns the policy that this policy is an upgrade of, if such a policy exists.
     * Note that this method has to guess a bit because there is no direct information about this stored. If two
     * policies are created after a policy is cancelled for upgrade, only one of those policies will say it is
     * upgraded from that policy by this method, thus it is accurate for numerical reporting purposes, but historically
     * the other one might actually have been the upgrade.
     * @return Policy|null the previous policy if there is one.
     */
    public function getUpgradedFrom()
    {
        $user = $this->getUser();
        if (!$user || $this->hasPreviousPolicy()) {
            return null;
        }
        $policies = $user->getAllPolicyPolicies();
        // Get all the users policies that were cancelled in ascending order of cancellation.
        $cancelledUpgrade = [];
        foreach ($policies as $policy) {
            if ($policy->getStatus() == Policy::STATUS_CANCELLED &&
                $policy->getCancelledReason() == Policy::CANCELLED_UPGRADE) {
                $cancelledUpgrade[] = $policy;
            }
        }
        usort($cancelledUpgrade, function ($a, $b) {
            return ($a->getEnd() < $b->getEnd()) ? -1 : 1;
        });
        // Now match these to the oldest eligible created policy created within 24 hours.
        usort($policies, function ($a, $b) {
            return ($a->getStart() < $b->getStart()) ? -1 : 1;
        });
        $start = 0;
        foreach ($cancelledUpgrade as $prior) {
            $cancellationDate = $prior->getEnd()->getTimestamp();
            for ($i = $start; $i < count($policies); $i++) {
                $startDate = $policies[$i]->getStart()->getTimestamp();
                if ($startDate <= $cancellationDate) {
                    $start++;
                } elseif ($startDate < $cancellationDate + 60 * 60 * 24) {
                    if ($policies[$i]->getId() == $this->getId()) {
                        return $prior;
                    }
                    $start++;
                    break;
                }
            }
        }
        return null;
    }

    /**
     * @return BankAccount|null
     */
    public function getBacsBankAccount()
    {
        $bacsPaymentMethod = $this->getBacsPaymentMethod();
        if ($bacsPaymentMethod) {
            return $bacsPaymentMethod->getBankAccount();
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

    public function hasCheckoutPaymentMethod()
    {
        return $this->getPaymentMethod() instanceof CheckoutPaymentMethod;
    }

    /**
     * @return CheckoutPaymentMethod|null
     */
    public function getCheckoutPaymentMethod()
    {
        if ($this->hasCheckoutPaymentMethod()) {
            /** @var CheckoutPaymentMethod $paymentMethod */
            $paymentMethod = $this->getPaymentMethod();

            return $paymentMethod;
        }

        return null;
    }

    public function hasCardPaymentMethod()
    {
        return $this->hasCheckoutPaymentMethod() || $this->hasPolicyOrPayerOrUserJudoPaymentMethod();
    }

    public function getCardPaymentMethod()
    {
        return $this->getCheckoutPaymentMethod() ?: $this->getPolicyOrPayerOrUserJudoPaymentMethod();
    }

    public function hasPolicyOrPayerOrUserJudoPaymentMethod()
    {
        // TODO: Eventually remove this method
        return $this->getPolicyOrPayerOrUserJudoPaymentMethod() instanceof JudoPaymentMethod;
    }

    public function getPolicyOrPayerOrUserJudoPaymentMethod()
    {
        // TODO: Eventually remove this method
        return $this->getJudoPaymentMethod() ?: null;
    }

    /**
     * This method is slightly different then others, and incorporates the getPolicyOrUserBacsPaymentMethod
     * @return bool
     */
    public function canUpdateBacsDetails()
    {
        /** @var BacsPaymentMethod $bacsPaymentMethod */
        $bacsPaymentMethod = $this->getPolicyOrUserBacsPaymentMethod();
        if ($bacsPaymentMethod instanceof BacsPaymentMethod &&
            $bacsPaymentMethod->getBankAccount()->isMandateInProgress()) {
            return false;
        }

        if ($this->hasBacsPaymentInProgress()) {
            return false;
        }

        return true;
    }

    public function setDontCancelIfUnpaid($dontCancelIfUnpaid)
    {
        $this->dontCancelIfUnpaid = $dontCancelIfUnpaid;
    }

    public function getDontCancelIfUnpaid()
    {
        return $this->dontCancelIfUnpaid;
    }

    public function init(User $user, PolicyTerms $terms, $validateExcess = true)
    {
        $user->addPolicy($this);
        if ($company = $user->getCompany()) {
            $company->addPolicy($this);
        }
        $this->setPolicyTerms($terms);

        // in the normal flow we should have policy terms before setting the phone
        // however, many test cases do not have it
        if ($this->getPremium() && $validateExcess) {
            $this->validateAllowedExcess();
        }
    }

    public function isCreateAllowed(\DateTime $date = null)
    {
        \AppBundle\Classes\NoOp::ignore([$date]);

        if (!$this->getUser()) {
            return false;
        }
        if (!$this->getUser()->getBillingAddress()) {
            return false;
        }

        // Only create 1 time
        if ($this->getPolicyNumber()) {
            return;
        }

        // Standard create would be null or pending
        // Multipay rejected - original user can still purchase policy
        if (!in_array($this->getStatus(), [
            null,
            self::STATUS_PENDING,
            self::STATUS_PENDING_RENEWAL,
            self::STATUS_DECLINED_RENEWAL,
            self::STATUS_MULTIPAY_REQUESTED,
            self::STATUS_MULTIPAY_REJECTED,
        ])) {
            return false;
        }

        return true;
    }

    public function create($seq, $prefix = null, \DateTime $startDate = null, $scodeCount = 1, $billing = null)
    {
        $issueDate = \DateTime::createFromFormat('U', time());
        if (!$startDate) {
            $startDate = \DateTime::createFromFormat('U', time());
            // No longer necessary to start 10 minutes in the future
            // $startDate->add(new \DateInterval('PT10M'));
        }

        if (!$this->isCreateAllowed($startDate)) {
            throw new \Exception(sprintf(
                'Unable to create policy %s. Missing user/address or invalid status (%s)',
                $this->getId(),
                $this->getStatus()
            ));
        }

        if (!$prefix) {
            $prefix = $this->getPolicyNumberPrefix();
        }

        $startDate->setTimezone($this->getUnderwriterTimeZone());
        $issueDate->setTimezone($this->getUnderwriterTimeZone());

        $this->setStart($startDate);
        $this->setIssueDate($issueDate);

        if ($billing) {
            $diff = $this->getStart()->diff($billing);
            if ($billing->format('d') > $this->getStart()->format('d') && $diff->d > 7) {
                $billing->sub(new \DateInterval('P1M'));
            }
            $this->setBilling($billing);
        } else {
            $this->setBilling($this->getStartForBilling());
        }
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

        // Renewals may have a status of PENDING_RENEWAL
        if (!$this->getStatus()) {
            $this->setStatus(self::STATUS_PENDING);
        }

        if (count($this->getSCodes()) == 0) {
            $this->createAddSCode($scodeCount);
        }

        $this->setLeadSource($this->getUser()->getLeadSource());
        $this->setLeadSourceDetails($this->getUser()->getLeadSourceDetails());
    }

    public function setViewedCancellationPage($viewedCancellationPage)
    {
        $this->viewedCancellationPage = $viewedCancellationPage;
    }

    public function getViewedCancellationPage()
    {
        return $this->viewedCancellationPage;
    }

    public function hasViewedCancellationPage()
    {
        return $this->viewedCancellationPage != null;
    }

    public function setRequestedCancellation($requestedCancellation)
    {
        $this->requestedCancellation = $requestedCancellation;
    }

    public function getRequestedCancellation()
    {
        return $this->requestedCancellation;
    }

    public function hasRequestedCancellation()
    {
        return $this->requestedCancellation != null;
    }

    public function setRequestedCancellationReason($requestedCancellationReason)
    {
        $this->requestedCancellationReason = $requestedCancellationReason;
    }

    public function getRequestedCancellationReason()
    {
        return $this->requestedCancellationReason;
    }

    public function setRequestedCancellationReasonOther($requestedCancellationReasonOther)
    {
        $this->requestedCancellationReasonOther = $requestedCancellationReasonOther;
    }

    public function getRequestedCancellationReasonOther()
    {
        return $this->requestedCancellationReasonOther;
    }

    public function setVisitedWelcomePage(\DateTime $date)
    {
        $this->visitedWelcomePage = $date;
    }

    public function getVisitedWelcomePage()
    {
        return $this->visitedWelcomePage;
    }

    public function getPremiumInstallmentCount()
    {
        if (!$this->isPolicy()) {
            return null;
        }

        return $this->getPremiumInstallments();
    }

    public function getPremiumInstallmentPrice($useAdjustedPrice = false, $estimate = false)
    {
        if (!$this->isPolicy() && !$estimate) {
            return null;
        }

        if (!$this->getPremiumInstallmentCount() && !$estimate) {
            return null;
        } elseif ($this->getPremiumPlan() == self::PLAN_YEARLY) {
            if ($useAdjustedPrice) {
                return $this->getPremium()->getAdjustedYearlyPremiumPrice();
            } else {
                return $this->getPremium()->getYearlyPremiumPrice();
            }
        } elseif ($this->getPremiumPlan() == self::PLAN_MONTHLY) {
            if ($useAdjustedPrice) {
                // TODO: What about final month??
                return $this->getPremium()->getAdjustedStandardMonthlyPremiumPrice();
            } else {
                return $this->getPremium()->getMonthlyPremiumPrice();
            }
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
        } elseif ($this->getPremiumPlan() == self::PLAN_YEARLY) {
            return $this->getPremium()->getYearlyGwp();
        } elseif ($this->getPremiumPlan() == self::PLAN_MONTHLY) {
            return $this->getPremium()->getGwp();
        } else {
            throw new \Exception(sprintf('Policy %s does not have correct installment amount', $this->getId()));
        }
    }

    /**
     * TODO: Should remove this
     */
    public function getYearlyPremiumPrice()
    {
        return $this->getPremium()->getYearlyPremiumPrice();
        //return $this->getPremiumInstallmentCount() * $this->getPremiumInstallmentPrice();
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
        // Policy upgrade should allow a refund regardless of claim status
        if ($this->getCancelledReason() == Policy::CANCELLED_UPGRADE) {
            return true;
        }

        // For all other cases, if there's a claim, then no not allow refund
        // Open claims should have transitioned to pending closed, and for those cases, do not allow a refund
        // - potentially would need to handle on a case by case basis
        if ($this->hasMonetaryClaimed(true) || $this->hasPendingClosedClaimed()) {
            return false;
        }

        if ($this->getCancelledReason() == Policy::CANCELLED_PICSURE_REQUIRED_EXPIRED) {
            return true;
        }

        if ($this->getCancelledReason() == Policy::CANCELLED_UNPAID ||
            $this->getCancelledReason() == Policy::CANCELLED_ACTUAL_FRAUD ||
            $this->getCancelledReason() == Policy::CANCELLED_SUSPECTED_FRAUD) {
            // Never refund for certain cancellation reasons
            return false;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_USER_REQUESTED &&
            !$this->getPolicyTerms()->isInstantUserCancellationEnabled()) {
            return true;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            return true;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_DISPOSSESSION ||
            $this->getCancelledReason() == Policy::CANCELLED_WRECKAGE) {
            return true;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_BADRISK) {
            throw new \UnexpectedValueException('Badrisk is not implemented');
        }
        return false;
    }

    public function getRefundAmount($skipAllowedCheck = false, $skipValidate = false, $countScheduled = false)
    {
        // Just in case - make sure we don't refund for non-cancelled policies
        if (!$this->isCancelled()) {
            return 0;
        }
        if (!$skipAllowedCheck) {
            if (!$this->isRefundAllowed()) {
                return 0;
            }
        }
        $offset = 0;
        if ($countScheduled) {
            $offset = $this->getScheduledPaymentRefundAmount();
        }
        // 3 factors determine refund amount
        // Cancellation Reason, Monthly/Annual, Claimed/NotClaimed
        $reason = $this->getCancelledReason();
        if ($reason == Policy::CANCELLED_COOLOFF || $reason == Policy::CANCELLED_PICSURE_REQUIRED_EXPIRED) {
            return $this->getCooloffPremiumRefund($skipValidate) - $offset;
        } else {
            return $this->getProratedPremiumRefund($this->getEnd()) - $offset;
        }
    }

    /**
     * Gives you the amount of coverholder commission that this policy should be refunded.
     * @return float the amount of coverholder commission that this policy should be refunded. It can be either
     *               negative or positive (or zero), but it should be interpreted as meaning that a positive value is
     *               an amount that must still be taken, and a negative amount means that commission is not owed but
     *               must actually be returned.
     */
    public function getRefundCoverholderCommissionAmount()
    {
        if (!$this->isCancelled() || !$this->isRefundAllowed()) {
            return 0;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            return $this->getCoverholderCommissionPaid();
        } else {
            return $this->getProratedCoverholderCommissionRefund($this->getEnd());
        }
    }

    /**
     * Gives you the amount of broker commission that this policy should be refunded.
     * @return float the amount of broker commission that this policy should be refunded. It can be either
     *               negative or positive (or zero), but it should be interpreted as meaning that a positive value is
     *               an amount that must still be taken, and a negative amount means that commission is not owed but
     *               must actually be returned.
     */
    public function getRefundBrokerCommissionAmount()
    {
        if (!$this->isCancelled() || !$this->isRefundAllowed()) {
            return 0;
        } elseif ($this->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            return $this->getBrokerCommissionPaid();
        } else {
            return $this->getProratedBrokerCommissionRefund($this->getEnd());
        }
    }

    public function getRefundCommissionAmount($skipAllowedCheck = false, $skipValidate = false)
    {
        // Just in case - make sure we don't refund for non-cancelled policies
        if (!$this->isCancelled()) {
            return 0;
        }
        if (!$skipAllowedCheck) {
            if (!$this->isRefundAllowed()) {
                return 0;
            }
        }
        // 3 factors determine refund amount
        // Cancellation Reason, Monthly/Annual, Claimed/NotClaimed
        if ($this->getCancelledReason() == Policy::CANCELLED_COOLOFF) {
            return $this->getCooloffCommissionRefund($skipValidate);
        } else {
            return $this->getProratedCoverholderCommissionRefund($this->getEnd()) +
                $this->getProratedBrokerCommissionRefund($this->getEnd());
        }
    }

    public function getCooloffPremiumRefund($skipValidate = false)
    {
        $amountToRefund = 0;
        // Cooloff should refund full amount (which should be equal to the last payment except for renewals)
        if ($paymentToRefund = $this->getLastSuccessfulUserPaymentCredit()) {
            $amountToRefund = $paymentToRefund->getAmount();
        }
        if ($amountToRefund > 0 && !$skipValidate) {
            $this->validateRefundAmountIsInstallmentPrice($paymentToRefund);
        }
        $paid = $this->getPremiumPaid();

        // we should never refund more than the user paid
        // especially relevent for cases with an automatic free month
        if ($amountToRefund > $paid) {
            return $paid;
        }

        return $amountToRefund;
    }

    public function getProratedPremium(\DateTime $date = null)
    {
        $used = $this->getPremium()->getYearlyPremiumPrice() * $this->getProrataMultiplier($date);

        return $this->toTwoDp($used);
    }

    public function getProratedPremiumRefund(\DateTime $date = null)
    {
        $used = $this->getProratedPremium($date);
        $paid = $this->getPremiumPaid();

        return $this->toTwoDp($paid - $used);
    }

    public function getCooloffCommissionRefund($skipValidate = false)
    {
        $amountToRefund = 0;
        $commissionToRefund = 0;
        // Cooloff should refund full amount (which should be equal to the last payment except for renewals)
        if ($paymentToRefund = $this->getLastSuccessfulUserPaymentCredit()) {
            $amountToRefund = $paymentToRefund->getAmount();
            $commissionToRefund = $paymentToRefund->getTotalCommission();
        }
        if ($amountToRefund > 0 && !$skipValidate) {
            $this->validateRefundAmountIsInstallmentPrice($paymentToRefund);
        }

        $paid = $this->getPremiumPaid();

        // we should never refund more than the user paid
        // especially relevent for cases with an automatic free month
        if ($amountToRefund > $paid) {
            return $this->getTotalCommissionPaid();
        }

        return $commissionToRefund;
    }

    /**
     * Gives you the total amount of commission that the policy should have paid up so far on a pro rata basis.
     * @param \DateTime|null $date is the date at which this should be correct with null meaning right now.
     * @return float the total amount of pro rata commission.
     */
    public function getProratedCommission(\DateTime $date = null)
    {
        return $this->toTwoDp($this->getYearlyTotalCommission() * $this->getProrataMultiplier($date));
    }

    /**
     * Calculates the amount of coverholder commission that should have been paid so far on a pro rata basis.
     * @param \DateTime|null $date is the date at which this calculation should be accurate, with null meaning now.
     * @return float the pro rata amount of coverholder commission.
     */
    public function getProratedCoverholderCommission(\DateTime $date = null)
    {
        return $this->toTwoDp($this->getYearlyCoverholderCommission() * $this->getProrataMultiplier($date));
    }

    /**
     * Calculates the amount of broker commission that should have been paid so far on a pro rata basis.
     * @param \DateTime|null $date is the date at which this calculation should be accurate, with null meaning now.
     * @return float the pro rata amount of broker commission.
     */
    public function getProratedBrokerCommission(\DateTime $date = null)
    {
        return $this->toTwoDp($this->getYearlyBrokerCommission() * $this->getProrataMultiplier($date));
    }

    /**
     * Get the coverholder commission that is outstanding at the given time on a pro rata basis.
     * @param \DateTime $date is the time at which the calculation is accurate.
     * @return float the amount of coverholder commission due.
     */
    public function getProratedCoverholderCommissionPayment(\DateTime $date)
    {
        return $this->toTwoDp($this->getProratedCoverholderCommission($date) -
            $this->getCoverholderCommissionPaid());
    }

    /**
     * Gives the amount of outstanding broker commission at the current time on a pro rata basis.
     * @param \DateTime $date is the time at which the calculation is accurate.
     * @return float the amount of broker commission due.
     */
    public function getProratedBrokerCommissionPayment(\DateTime $date)
    {
        return $this->toTwoDp($this->getProratedBrokerCommission($date) - $this->getBrokerCommissionPaid());
    }

    /**
     * Gives you the amount of coverholder commission that should be refunded on a pro rata basis.
     * @param \DateTime $date is the date at which this calculation should be accurate.
     * @return float the amount of coverholder commission in the refund.
     */
    public function getProratedCoverholderCommissionRefund(\DateTime $date)
    {
        return $this->getCoverholderCommissionPaid() - $this->getProratedCoverholderCommission($date);
    }

    /**
     * Gives you the amount of broker commission that should be refunded on a pro rata basis.
     * @param \DateTime $date is the date at which this calculation should be accurate.
     * @return float the amount of broker commission in the refund.
     */
    public function getProratedBrokerCommissionRefund(\DateTime $date)
    {
        return $this->getBrokerCommissionPaid() - $this->getProratedBrokerCommission($date);
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
            $date = \DateTime::createFromFormat('U', time());
        }
        $date = $this->endOfDay($date);

        $start = $this->startOfDay($this->getStart());
        $diff = $start->diff($date);
        $days = $diff->days;

        if ($days > $this->getDaysInPolicyYear()) {
            $days = $this->getDaysInPolicyYear();
        }

        return $days;
    }

    public function getRemainingPremiumPaid($payments)
    {
        return $this->toTwoDp($this->getPremiumPaid() - $this->getPremiumPaid($payments));
    }

    public function getUserPremiumPaid(\DateTime $date = null)
    {
        return $this->getPremiumPaid($this->getSuccessfulUserPayments(), $date);
    }

    public function getPremiumPaid($payments = null, \DateTime $date = null)
    {
        $paid = 0;
        if ($payments === null) {
            $payments = $this->getPayments($date);
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

    public function getTotalCommissionPaid($payments = null, $includePending = false)
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
            } elseif ($includePending && $payment instanceof BacsPayment &&
                $payment->getStatus() == BacsPayment::STATUS_PENDING) {
                $totalCommission += $payment->getTotalCommission();
            }
        }

        return $this->toTwoDp($totalCommission);
    }

    /**
     * Returns the total of coverholder commission in the policy's successful payments.
     * @param array|null $payments is an optional subset of payments to use.
     * @return float the totalled coverholder commission.
     */
    public function getCoverholderCommissionPaid($payments = null)
    {
        $coverholderCommission = 0;
        if (!$this->isPolicy()) {
            return 0;
        }
        if ($payments === null) {
            $payments = $this->getPayments();
        }
        foreach ($payments as $payment) {
            if ($payment->isSuccess()) {
                $coverholderCommission += $payment->getCoverholderCommission();
            }
        }
        return $this->toTwoDp($coverholderCommission);
    }

    public function getBrokerCommissionPaid($payments = null)
    {
        $brokerCommission = 0;
        if (!$this->isPolicy()) {
            return 0;
        }
        if ($payments === null) {
            $payments = $this->getPayments();
        }

        foreach ($payments as $payment) {
            if ($payment->isSuccess()) {
                $brokerCommission += $payment->getBrokerCommission();
            }
        }

        return $this->toTwoDp($brokerCommission);
    }

    public function getUnderwritingOutstandingPremium()
    {
        // From Dylan:
        // if paid , then payment accounted
        // if not paid/paying, then it should not show as outstanding amount due (as we won't be receiving it)
        return $this->isActive() ? $this->getOutstandingPremium() : 0;
    }

    public function getOutstandingPremium()
    {
        return $this->toTwoDp($this->getPremium()->getYearlyPremiumPrice() - $this->getPremiumPaid());
    }

    public function getPendingBacsPayments($includePending = false)
    {
        $pendingPayments = [];
        $statuses = [BacsPayment::STATUS_SUBMITTED, BacsPayment::STATUS_GENERATED];
        if ($includePending) {
            $statuses[] = BacsPayment::STATUS_PENDING;
        }
        $payments = $this->getPaymentsByType(BacsPayment::class);
        foreach ($payments as $payment) {
            /** @var BacsPayment $payment */
            if (in_array($payment->getStatus(), $statuses)) {
                $pendingPayments[] = $payment;
            }
        }

        return $pendingPayments;
    }

    public function getPendingBacsPaymentsTotal($includePending = false)
    {
        $total = 0;
        foreach ($this->getPendingBacsPayments($includePending) as $payment) {
            /** @var BacsPayment $payment */
            $total += $payment->getAmount();
        }

        return $total;
    }

    public function getPendingBacsPaymentsTotalCommission($includePending = false)
    {
        $totalCommission = 0;
        foreach ($this->getPendingBacsPayments($includePending) as $payment) {
            /** @var BacsPayment $payment */
            $totalCommission += $payment->getTotalCommission();
        }

        return $totalCommission;
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

    public function getRiskColourText()
    {
        $risk = $this->getRisk();
        if ($risk == self::RISK_LEVEL_LOW) {
            return 'green';
        } elseif ($risk == self::RISK_LEVEL_MEDIUM) {
            return 'amber';
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
            $date = \DateTime::createFromFormat('U', time());
        }

        if ($this->getPendingCancellation()) {
            return self::RISK_PENDING_CANCELLATION_POLICY;
        }

        // Once of the few cases where we want to check linked claims as can affect risk rating
        if ($this->hasMonetaryClaimed(true, true)) {
            $currentApprovedClaimCount = count($this->getApprovedClaims(true, true));
            if ($this->hasPreviousPolicy() &&
                count($this->getPreviousPolicy()->getApprovedClaims(true, true)) == 0 &&
                $currentApprovedClaimCount == 1) {
                return self::RISK_RENEWED_NO_PREVIOUS_CLAIM;
            }

            // a self claim can be before the pot is adjusted.  also a pot zero is not always due to a self claim
            return self::RISK_CONNECTED_SELF_CLAIM;
            // return self::RISK_LEVEL_HIGH;
        }

        // If all the connections are only to the same user (multiple policy), then ignore that aspect
        if (count($this->getStandardConnections()) > 0 &&
            count($this->getStandardConnections()) > count($this->getStandardSelfConnections())) {
            // Connected and value of their pot is zero
            if ($this->areEqualToFourDp($this->getPotValue(), 0) ||
                $this->areEqualToFourDp($this->getStandardPotValue(), 0)) {
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
        // and not a renewal policy
        if ($this->isPolicyWithin30Days($date) && !$this->getPreviousPolicy()) {
            return self::RISK_NOT_CONNECTED_NEW_POLICY;
            //return self::RISK_LEVEL_HIGH;
        } else {
            // No connections & claiming after the 1st month
            return self::RISK_NOT_CONNECTED_ESTABLISHED_POLICY;
            // return self::RISK_LEVEL_MEDIUM;
        }
    }

    /**
     * Tells you what generation of renewals this policy is. If it is not a renewal it will return 1, if it is
     * a renewal of a policy that is not a renewal it will return 2, etc.
     * @return int the generation number that it is.
     */
    public function getGeneration()
    {
        $policy = $this;
        $generation = 0;
        while ($policy) {
            $policy = $policy->getPreviousPolicy();
            $generation++;
        }
        return $generation;
    }

    public function isPolicyWithin21Days($date = null)
    {
        if (!$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        return $this->getStart()->diff($date)->days <= 21;
    }

    public function isPolicyWithin30Days($date = null)
    {
        if (!$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        return $this->getStart()->diff($date)->days <= 30;
    }

    public function isPolicyOldEnough($days, \DateTime $date = null)
    {
        if (!$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        /** @var \DateTime $start */
        $start = $this->getStart();
        $diff = $start->diff($date);

        return $diff->days >= $days && !$diff->invert;
    }

    public function isPolicyExpiredWithin30Days($unrenewed = true, $date = null)
    {
        if (!$this->getEnd()) {
            return null;
        }

        if ($unrenewed) {
            if (!$this->hasNextPolicy() || !$this->getNextPolicy()->isUnrenewed()) {
                return false;
            }
        }

        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        return $date->diff($this->getEnd())->days <= 30;
    }

    public function isPolicyWithin60Days($date = null)
    {
        if (!$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        return $this->getStart()->diff($date)->days < 60;
    }

    public function isBeforePolicyStarted($date = null)
    {
        if (!$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
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
        // TODO: This needs removing so it's always just 60 days
        if ($pseudo) {
            // add 14 days
            $cliffDate->add(new \DateInterval('P60D'));
        } else {
            // add 60 days
            $cliffDate->add(new \DateInterval('P60D'));
        }

        return $cliffDate;
    }

    public function hasMonetaryClaimed($includeApproved = false, $includeLinked = false)
    {
        $count = count($this->getMonetaryClaimed($includeApproved));
        if ($includeLinked) {
            $count += count($this->getMonetaryLinkedClaimed($includeApproved));
        }
        return $count > 0;
    }

    public function getMonetaryClaimed($includeApproved = false)
    {
        $claims = [];
        foreach ($this->claims as $claim) {
            /** @var Claim $claim */
            if ($claim->isMonetaryClaim($includeApproved)) {
                $claims[] = $claim;
            }
        }

        return $claims;
    }

    public function getMonetaryLinkedClaimed($includeApproved = false)
    {
        $claims = [];
        foreach ($this->linkedClaims as $claim) {
            if ($claim->isMonetaryClaim($includeApproved)) {
                $claims[] = $claim;
            }
        }

        return $claims;
    }

    public function hasPendingClosedClaimed()
    {
        $count = count($this->getPendingClosedClaimed());

        return $count > 0;
    }

    public function getPendingClosedClaimed()
    {
        $claims = [];
        foreach ($this->claims as $claim) {
            /** @var Claim $claim */
            if ($claim->getStatus() == Claim::STATUS_PENDING_CLOSED) {
                $claims[] = $claim;
            }
        }

        return $claims;
    }

    public function isExpired()
    {
        return in_array($this->getStatus(), self::$expirationStatuses);
    }

    public function isUnrenewed()
    {
        return in_array($this->getStatus(), [self::STATUS_UNRENEWED]);
    }

    public function isCancelled()
    {
        return $this->getStatus() == self::STATUS_CANCELLED;
    }

    public function isEnded()
    {
        return $this->isExpired() || $this->isCancelled();
    }

    public function isClaimable()
    {
        return in_array($this->getStatus(), [
           self::STATUS_ACTIVE,
           self::STATUS_UNPAID,
           self::STATUS_EXPIRED_CLAIMABLE,
        ]);
    }

    /**
     * User declined indicates that this user should not be allowed to purchase/repurchase/renew any policies
     */
    public function isCancelledWithUserDeclined()
    {
        // Not cancelled, obviously false
        if ($this->getStatus() != self::STATUS_CANCELLED) {
            return false;
        }
        // Fraud/bad risk will always be user declined to purchase additional
        if (in_array($this->getCancelledReason(), [
            self::CANCELLED_ACTUAL_FRAUD,
            self::CANCELLED_SUSPECTED_FRAUD,
            self::CANCELLED_BADRISK,
        ])) {
            return true;
        }

        // upgrade is a pre-approved user accepted - upgraded policy should be used for status
        if ($this->getCancelledReason() == self::CANCELLED_UPGRADE) {
            return false;
        }

        // User has a cancelled policy for any reason w/approved claimed and policy was not paid in full
        if (count($this->getApprovedClaims(true, true, true)) > 0 &&
            !$this->isFullyPaid()) {
            return true;
        }

        // User has a withdrawn or declined claim and policy was cancelled/unpaid
        if ($this->getCancelledReason() == self::CANCELLED_UNPAID &&
            count($this->getWithdrawnDeclinedClaims(true, true)) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Policy declined indicates that this specific policy (e.g. phone)
     * should not be renewed/repurchase
     */
    public function isCancelledWithPolicyDeclined()
    {
        // Not cancelled, obviously false
        if ($this->getStatus() != self::STATUS_CANCELLED) {
            return false;
        }
        // Fraud/bad risk will always be user declined to purchase additional
        if (in_array($this->getCancelledReason(), [
            self::CANCELLED_DISPOSSESSION,
            self::CANCELLED_WRECKAGE,
        ])) {
            return true;
        }

        return false;
    }

    public function hasCorrectIptRate()
    {
        if (!$this->getPremium()) {
            return null;
        }

        return $this->areEqualToTwoDp(
            $this->getPremium()->getIptRate(),
            $this->getCurrentIptRate($this->getStart())
        );
    }

    public function isCooloffCancelled()
    {
        return $this->isCancelledForReason(self::CANCELLED_COOLOFF);
    }

    public function isUnpaidCancelled()
    {
        return $this->isCancelledForReason(self::CANCELLED_UNPAID);
    }

    private function isCancelledForReason($reason)
    {
        return $this->isCancelled() && $this->getCancelledReason() == $reason;
    }

    public function canCancel($reason, $date = null, $ignoreClaims = false, $extendedCooloff = true)
    {
        // Doesn't make sense to cancel
        if (in_array($this->getStatus(), [
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_EXPIRED_CLAIMABLE,
            self::STATUS_EXPIRED_WAIT_CLAIM
        ])) {
            return false;
        }

        if (!$this->isPolicy()) {
            return false;
        }

        // Any claims must be completed before cancellation is allowed
        if ($this->hasOpenClaim() && !$ignoreClaims) {
            return false;
        }

        if ($this->getStatus() === self::STATUS_UNPAID && $this->dontCancelIfUnpaid) {
            return false;
        }

        if ($reason == Policy::CANCELLED_COOLOFF) {
            return $this->isWithinCooloffPeriod($date, $extendedCooloff) && !$this->hasMonetaryClaimed(true);
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
        $amount = 0;
        if ($payment) {
            $amount = $payment->getAmount();
        }
        if (!$this->areEqualToTwoDp($amount, $this->getPremiumInstallmentPrice(true))) {
            throw new \InvalidArgumentException(sprintf(
                'Failed to validate [policy %s] refund amount (%f) does not match premium price (%f)',
                $this->getPolicyNumber(),
                $payment->getAmount(),
                $this->getPremiumInstallmentPrice(true) ? $this->getPremiumInstallmentPrice(true) : -1
            ));
        }

        return true;
    }

    public function isWithinCooloffPeriod($date = null, $extended = true)
    {
        if (!$this->isPolicy() || !$this->getStart()) {
            return null;
        }

        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if ($extended) {
            return $this->getStart()->diff($date)->days < 30;
        } else {
            return $this->getStart()->diff($date)->days < 14;
        }
    }

    public function hasEndedInLast30Days($date = null)
    {
        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        return $this->getEnd()->diff($date)->days < 30;
    }

    public function shouldCancelPolicy($date = null)
    {
        if (!$this->isValidPolicy() || !$this->isActive()) {
            return false;
        }

        if ($this->getStatus() === self::STATUS_UNPAID && $this->dontCancelIfUnpaid) {
            return false;
        }

        // if its an initial (not renewal) valid policy without a payment, probably it should be expired
        if (!$this->hasPreviousPolicy() && !$this->getLastSuccessfulUserPaymentCredit() &&
            !$this->hasPolicyOrUserBacsPaymentMethod()) {
            throw new \Exception(sprintf(
                'Policy %s does not have a success payment - should be expired?',
                $this->getId()
            ));
        }

        // if it has a pending bacs payment it can stay for the time being.
        if (count($this->getPendingBacsPayments(true)) > 0) {
            return false;
        }

        if ($date == null) {
            $date = new \DateTime('now', SoSure::getSoSureTimezone());
        }

        return $date >= $this->getPolicyExpirationDate($date);
    }

    public function shouldExpirePolicy($date = null)
    {
        if (!$this->isValidPolicy() || !$this->isActive()) {
            return false;
        }

        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        return $date >= $this->getEnd();
    }

    public function hasPolicyExpirationDate(\DateTime $date = null)
    {
        try {
            $this->getPolicyExpirationDate($date);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Note that expiration date is for cancellations only and may be after policy end date
     */
    public function getPolicyExpirationDate(\DateTime $date = null)
    {
        if (!$this->isActive()) {
            return null;
        }

        if (!$date) {
            $date = new \DateTime('now', SoSure::getSoSureTimezone());
        }

        // Yearly payments are a bit different
        if ($this->getPremiumPlan() == self::PLAN_YEARLY) {
            if ($this->areEqualToTwoDp(0, $this->getOutstandingPremiumToDate($date))) {
                return $this->endOfDay($this->getEnd());
            } elseif ($this->areEqualToTwoDp(0, $this->getUserPremiumPaid($date))) {
                $thirthDays = clone $this->getStart();
                $thirthDays = $thirthDays->add(new \DateInterval('P30D'));

                return $thirthDays;
            } else {
                throw new \Exception(sprintf(
                    'Failed to find a yearly date with a 0 outstanding premium (%f). Policy %s/%s',
                    $this->getOutstandingPremiumToDate($date),
                    $this->getPolicyNumber(),
                    $this->getId()
                ));
            }
        }

        $billingDate = clone $this->getBilling();
        $successes = $this->getPremium()->getNumberOfMonthlyPayments(
            $this->getTotalSuccessfulPayments($date, true)
        ) ?: 0;
        if ($successes < 0) {
            $successes = 0;
        }
        $billingDate->add(new \DateInterval("P{$successes}M30D"));
        $billingDate = $this->startOfDay($billingDate);

        // Unpaid policies with a cancellation date after the end of policy, should be adjusted to cancel
        // 1 day prior to end date
        if ($this->getStatus() == self::STATUS_UNPAID && $billingDate > $this->getEnd()) {
            $billingDate = clone $this->getEnd();
            $billingDate->sub(new \DateInterval('P1D'));
            $billingDate = $this->startOfDay($billingDate);
        }

        return $billingDate;
    }

    public function getPolicyExpirationDateDays(\DateTime $date = null)
    {
        if (!$this->getPolicyExpirationDate()) {
            return null;
        }

        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $diff = $this->getPolicyExpirationDate()->diff($date);
        $days = $diff->days;

        return $days;
    }

    public function getUnreplacedConnectionCancelledPolicyInLast30Days($date = null)
    {
        $unreplacedConnections = [];
        foreach ($this->getConnections() as $connection) {
            if ($connection->getReplacementConnection() || $connection instanceof RewardConnection) {
                continue;
            }
            $policy = $connection->getLinkedPolicy();
            if (!$policy && !$connection instanceof RewardConnection) {
                throw new \Exception(sprintf('Invalid connection in policy %s', $this->getId()));
            }
            if ($policy->isCancelled() && $policy->hasEndedInLast30Days($date)) {
                $unreplacedConnections[] = $connection;
            }
        }

        if (count($unreplacedConnections) == 0) {
            return null;
        }

        // sort older to more recent
        usort($unreplacedConnections, function ($a, $b) {
            return $a->getLinkedPolicy()->getEnd() > $b->getLinkedPolicy()->getEnd();
        });

        return $unreplacedConnections[0];
    }

    public function hasNetworkClaimedInLast30Days($date = null, $includeOpen = false)
    {
        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
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

    public function hasOpenNetworkClaim()
    {
        foreach ($this->getStandardConnections() as $connection) {
            $policy = $connection->getLinkedPolicy();
            if (!$policy) {
                throw new \Exception(sprintf('Invalid connection in policy %s', $this->getId()));
            }
            if ($policy->hasOpenClaim()) {
                return true;
            }
        }

        return false;
    }

    public function hasUnprocessedMonetaryNetworkClaim()
    {
        foreach ($this->getNetworkClaims(true) as $claim) {
            /** @var Claim $claim */
            if (!$claim->getProcessed()) {
                return true;
            }
        }

        return false;
    }

    public function getNetworkClaims($monitaryOnly = false, $includeApproved = false)
    {
        $claims = [];
        foreach ($this->getStandardConnections() as $connection) {
            foreach ($connection->getLinkedClaimsDuringPeriod() as $claim) {
                /** @var Claim $claim */
                if (!$monitaryOnly || $claim->isMonetaryClaim($includeApproved)) {
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

        if ($this->getMaxPot() < $potValue && ($potValue - $this->getMaxPot()) < 10) {
            $potValue = $this->getMaxPot();
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
        return $this->getStatus() != null && $this->getPremium() != null;
    }

    public function age()
    {
        if (!$this->getStart()) {
            return null;
        }

        $now = \DateTime::createFromFormat('U', time());

        $days = $now->diff($this->getStart())->days;
        if ($now < $this->getStart()) {
            return 0 - $days;
        } else {
            return $days;
        }
    }

    public function getPolicyPrefix($environment)
    {
        $prefix = null;
        if ($environment != 'prod') {
            $prefix = mb_strtoupper($environment);
        } elseif ($this->getUser() && $this->getUser()->hasSoSureEmail()) {
            // any emails with @so-sure.com will generate an invalid policy
            $prefix = self::PREFIX_INVALID;
        }
        return $prefix;
    }

    /**
     * Tells you if the policy has a given policy number prefix.
     * @param string|null $prefix is the prefix you are looking for.
     * @return boolean true if the policy has this prefix and false if not.
     */
    public function hasPolicyPrefix($prefix)
    {
        return mb_strpos($this->getPolicyNumber(), $prefix) === 0;
    }

    /**
     * Tells you if this policy has the invalid policy prefix.
     * @return boolean true if the prefix says it is invalid and false if not.
     */
    public function isPrefixInvalidPolicy()
    {
        return $this->hasPolicyPrefix(self::PREFIX_INVALID);
    }

    /**
     * Tells you if a given policy is valid.
     * @return boolean true if it is valid and false if not.
     */
    public function isValidPolicy()
    {
        if (!$this->isPolicy()) {
            return false;
        }
        return ($this->getPolicyNumber() && !$this->isPrefixInvalidPolicy());
    }

    public function isBillablePolicy()
    {
        // We should only bill policies that are active or unpaid
        // Doesn't make sense to bill expired or cancelled policies
        return $this->isActive();
    }

    public function getSentInvitations($onlyProcessed = true)
    {
        $userId = $this->getUser() ? $this->getUser()->getId() : null;
        return array_filter($this->getInvitationsAsArray(), function ($invitation) use ($userId, $onlyProcessed) {
            if ($invitation instanceof AppNativeShareInvitation) {
                return false;
            }

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
     * Cancels the policy itself. Does not send out emails etc so should be called via the PoicyService::cancel method.
     * @param string    $reason     Reason for cancellation. Should be Policy::CANCELLED_*.
     * @param \DateTime $date       Date of cancellation.
     * @param boolean   $fullRefund Should the user get a full refund?
     */
    public function cancel($reason, \DateTime $date = null, $fullRefund = false)
    {
        if (!$this->getId()) {
            throw new \Exception('Unable to cancel a policy that is missing an id');
        }
        if ($reason == self::CANCELLED_COOLOFF && $fullRefund) {
            throw new \Exception('Cooloff automatically provides full refund. Full Refund flag should not be set.');
        }
        if (!$this->canCancel($reason, $date)) {
            throw new \Exception(sprintf('Unable to cancel policy %s/%s.', $this->getPolicyNumber(), $this->getId()));
        }
        if ($date === null) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $this->setStatus(Policy::STATUS_CANCELLED);
        $this->setCancelledReason($reason);
        $this->setEnd($date);
        $this->setCancelledFullRefund($fullRefund);
        // zero out the connection value for connections bound to this policy
        foreach ($this->getConnections() as $networkConnection) {
            $networkConnection->clearValue();
            if ($networkConnection instanceof RewardConnection) {
                continue;
            }
            if ($inversedConnection = $networkConnection->findInversedConnection()) {
                $inversedConnection->prorateValue($date);
            }
            $networkConnection->getLinkedPolicy()->updatePotValue();
        }
        // Cancel any scheduled payments
        $this->cancelScheduledPayments();
        $this->updatePotValue();
    }

    public function cancelScheduledPayments()
    {
        foreach ($this->getScheduledPayments() as $scheduledPayment) {
            if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED) {
                $scheduledPayment->cancel('Cancelled as all scheduled payments cancelled');
            }
        }
    }

    /**
     * @param boolean   $autoRenew Autorenewal needs a bit of leeway in terms of timing in case of process failure
     * @param \DateTime $date
     */
    public function isRenewalAllowed($autoRenew = false, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!in_array($this->getStatus(), [
            self::STATUS_PENDING_RENEWAL,
            self::STATUS_DECLINED_RENEWAL,
        ])) {
            return false;
        }

        if ($this->getRenewalExpiration()) {
            $tooLate = clone $this->getRenewalExpiration();
            if ($autoRenew) {
                // Give a bit of leeway in case we have problems with process, but
                // if the policy is supposed to be renewed and its more than 7 days
                // then we really do have an issue and probably need to manually sort out
                $tooLate = $tooLate->add(new \DateInterval('P7D'));
            }
            if ($date > $tooLate) {
                return false;
            }
        }

        if ($this->getPreviousPolicy()->getStatus() == self::STATUS_CANCELLED) {
            return false;
        }

        return true;
    }

    public function isUnRenewalAllowed(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!in_array($this->getStatus(), [
            self::STATUS_PENDING_RENEWAL,
            self::STATUS_DECLINED_RENEWAL,
        ])) {
            return false;
        }

        if (!$this->getRenewalExpiration() || $date < $this->getRenewalExpiration()) {
            return false;
        }

        return true;
    }

    public function declineRenew(\DateTime $date = null)
    {
        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!in_array($this->getStatus(), [
            self::STATUS_PENDING_RENEWAL,
        ])) {
            throw new \Exception(sprintf(
                'Policy %s can only be declined if it is pending renewal',
                $this->getId()
            ));
        }

        $this->setStatus(Policy::STATUS_DECLINED_RENEWAL);
    }

    public function renew($discount, $autoRenew = false, \DateTime $date = null)
    {
        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        // For autorenewals, no need to warn if previous policy was cancelled
        if ($autoRenew && $this->getPreviousPolicy()->getStatus() == self::STATUS_CANCELLED) {
            return false;
        }

        if (!$this->isRenewalAllowed($autoRenew, $date)) {
            throw new \Exception(sprintf(
                'Unable to renew policy %s as status is incorrect or its too late',
                $this->getId()
            ));
        }

        $this->setStatus(Policy::STATUS_RENEWAL);
        // clear the max allowed renewal date
        $this->setRenewalExpiration(null);

        if ($discount && $discount > 0) {
            if (!$this->areEqualToTwoDp($discount, $this->getPreviousPolicy()->getPotValue())) {
                throw new \Exception(sprintf(
                    'Invalid discount amount used for renewal. Policy %s. %f != %f',
                    $this->getId(),
                    $discount,
                    $this->getPreviousPolicy()->getPotValue()
                ));
            }
            $this->getPremium()->setAnnualDiscount($discount);
        }

        foreach ($this->getPreviousPolicy()->getStandardConnections() as $connection) {
            /** @var Connection $connection */
            $renew = count($this->getRenewalConnections()) < $this->getMaxConnectionsLimit();
            if ($connection->getLinkedPolicy()->isActive() &&
                $connection->getLinkedPolicy()->isConnected($this->getPreviousPolicy())) {
                $this->addRenewalConnection($connection->createRenewal($renew));
            } elseif ($connection->getLinkedPolicyRenewal() &&
                $connection->getLinkedPolicyRenewal()->isActive() &&
                $connection->getLinkedPolicyRenewal()->isConnected($this->getPreviousPolicy())
            ) {
                $this->addRenewalConnection($connection->createRenewal($renew));
            }
        }

        return true;
    }

    public function unrenew(\DateTime $date = null)
    {
        if (!$this->isUnRenewalAllowed($date)) {
            throw new \Exception(sprintf(
                'Unable to unrenew policy %s as status is incorrect or its too late',
                $this->getId()
            ));
        }

        $this->setStatus(Policy::STATUS_UNRENEWED);
    }

    /**
     * @param \DateTime|null $date
     * @throws \Exception
     */
    public function activate(\DateTime $date = null)
    {
        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!in_array($this->getStatus(), [
            self::STATUS_RENEWAL,
        ])) {
            throw new \Exception('Unable to activate a policy if status is not renewal');
        }

        if ($this->getPreviousPolicy() && !in_array($this->getPreviousPolicy()->getStatus(), [
            self::STATUS_EXPIRED,
            self::STATUS_EXPIRED_CLAIMABLE,
            self::STATUS_EXPIRED_WAIT_CLAIM,
        ])) {
            throw new \Exception(sprintf(
                'Previous policy for %s must be expired before we can activate renewal',
                $this->getId()
            ));
        }

        // Give a bit of leeway in case we have problems with process, but
        // if the policy is supposed to be renewed and its more than 7 days
        // then we really do have an issue and probably need to manually sort out
        $tooLate = clone $this->getStart();
        $tooLate = $tooLate->add(new \DateInterval('P7D'));
        if ($date < $this->getStart() || $date > $tooLate) {
            throw new \Exception(
                sprintf(
                    'Unable to activate policy %s if not between policy dates. Must be after %s and before %s',
                    $this->getId(),
                    $this->getStart()->format('Y-m-d H:i:s'),
                    $tooLate->format('Y-m-d H:i:s')
                )
            );
        }

        $this->setStatus(Policy::STATUS_ACTIVE);

        /**
         * Before we begin updating the connections and the pot we really need to make sure that we have
         * the correct payment details on the renewal policy.
         * In the case of BACs this should be the same account as the existing policy
         * and so we can just copy them over.
         * However if the amount has changed, we will need to notify the account holder.
         */
        if ($this->getPreviousPolicy() && $this->getPreviousPolicy()->getPaymentMethod()) {
            $this->setPaymentMethod($this->getPreviousPolicy()->getPaymentMethod());
        }

        foreach ($this->getRenewalConnections() as $connection) {
            if ($connection->getRenew()) {
                // There needs to be an inversed connection
                if (!$connection->findInversedConnection()) {
                    if ($connection->getLinkedPolicy()->isRenewal() &&
                        $connection->getLinkedPolicy()->isActive()) {
                        continue;
                    }
                }
                $newConnection = new StandardConnection();
                $newConnection->setLinkedUser($connection->getLinkedUser());
                $newConnection->setLinkedPolicy($connection->getLinkedPolicy());
                $newConnection->setValue($this->getAllowedConnectionValue($date));
                $newConnection->setPromoValue($this->getAllowedPromoConnectionValue($date));
                $newConnection->setExcludeReporting(!$this->isValidPolicy());
                $newConnection->setDate($date);
                $this->addConnection($newConnection);
            } else {
                if ($inversedConnection = $connection->findInversedConnection()) {
                    $inversedConnection->prorateValue($date);
                    $inversedConnection->getSourcePolicy()->updatePotValue();
                    // listener on connection will notify user
                } else {
                    // TODO: Not sure about this one...
                    throw new \Exception(sprintf(
                        'Unable to find inverse connection %s (accepted renewals: %s)',
                        $connection->getId(),
                        json_encode($this->getAcceptedConnectionsRenewalIds())
                    ));
                }
            }
        }

        $this->updatePotValue();
    }

    public function createPendingRenewal(PolicyTerms $terms, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!$this->canCreatePendingRenewal($date)) {
            throw new \Exception(sprintf('Unable to create a pending renewal for policy %s', $this->getId()));
        }

        if ($this instanceof SalvaPhonePolicy) {
            $newPolicy = new HelvetiaPhonePolicy();
        } else {
            $newPolicy = new static();
        }
        $this->setPolicyDetailsForPendingRenewal($newPolicy, $this->getEnd(), $terms);
        $newPolicy->setStatus(Policy::STATUS_PENDING_RENEWAL);
        // don't allow renewal after the end the current policy
        $newPolicy->setRenewalExpiration($this->getEnd());

        $newPolicy->init($this->getUser(), $terms);

        $this->link($newPolicy);

        return $newPolicy;
    }

    public function createRepurchase(PolicyTerms $terms, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!$this->canRepurchase()) {
            throw new \Exception(sprintf('Unable to repurchase for policy %s', $this->getId()));
        }

        $newPolicy = new static();
        $this->setPolicyDetailsForRepurchase($newPolicy, $date);
        $newPolicy->setStatus(null);

        $newPolicy->init($this->getUser(), $terms);

        // Cancelled policies that were not fully paid should link claims to the renewal policy
        if ($this->isCancelledAndPaymentOwed()) {
            foreach ($this->getApprovedClaims() as $claim) {
                $newPolicy->addLinkedClaim($claim);
            }
        }

        return $newPolicy;
    }

    /**
     * Expire the policy itself, however, this should be done via the policy server in order to
     * send out all the emails, etc
     *
     */
    public function expire(\DateTime $date = null)
    {
        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }
        /** @var \DateTime $dateNotNull */
        $dateNotNull = $date;

        if ($date < $this->getEnd()) {
            throw new \Exception('Unable to expire a policy prior to its end date');
        }

        if (!$this->isActive()) {
            throw new \Exception('Unable to expire a policy if status is not active or unpaid');
        }

        $this->setStatus(Policy::STATUS_EXPIRED_CLAIMABLE);

        // TODO: determine how to handle pot

        // Should never happen, but just in case, cancel any scheduled payments
        foreach ($this->getScheduledPayments() as $scheduledPayment) {
            if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED) {
                $scheduledPayment->setStatus(ScheduledPayment::STATUS_CANCELLED);
            }
        }

        $this->updatePotValue();

        if ($this->greaterThanZero($this->getPotValue())) {
            // Promo pot reward
            if ($this->greaterThanZero($this->getPromoPotValue())) {
                $reward = new SoSurePotRewardPayment();
                $reward->setDate(clone $dateNotNull);
                $reward->setAmount($this->toTwoDp(0 - $this->getPromoPotValue()));
                if ($this->isRenewalPending() || $this->isRenewed()) {
                    $reward->setNotes(sprintf(
                        'Pot Reward (so-sure marketing) applied to renewed policy %s',
                        $this->getNextPolicy()->getPolicyNumber()
                    ));
                } else {
                    $reward->setNotes('Pot Reward (so-sure marketing)');
                }
                $this->addPayment($reward);
            }

            // Normal pot reward
            $reward = new PotRewardPayment();
            $reward->setDate(clone $dateNotNull);
            $reward->setAmount($this->toTwoDp(0 - ($this->getStandardPotValue())));
            if ($this->isRenewalPending() || $this->isRenewed()) {
                $reward->setNotes(sprintf(
                    'Pot Reward (Salva) applied to renewed policy %s',
                    $this->getNextPolicy()->getPolicyNumber()
                ));
            } else {
                $reward->setNotes(sprintf('Pot Reward (Salva)'));
            }
            $this->addPayment($reward);

            // We can't give cashback + give a discount on the next year's policy as that would be
            // discounting twice - should never occur
            if ($this->hasCashback() && $this->getNextPolicy() &&
                $this->getNextPolicy()->getPremium()->hasAnnualDiscount()) {
                throw new \Exception(sprintf(
                    'Cashback was requested, yet there is a discount on the next policy. %s',
                    $this->getId()
                ));
            }

            if ($this->hasCashback() && $this->getCashback()) {
                // TODO: Should we be checking cashback status?
                $this->getCashback()->setDate(clone $dateNotNull);
                $this->getCashback()->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
                $this->getCashback()->setAmount($this->getPotValue());
            } elseif ($this->isRenewalPending() || $this->isRenewed()) {
                $discount = new PolicyDiscountPayment();
                $discount->setAmount($this->getPotValue());
                if ($this->getNextPolicy()->getStart()) {
                    $discount->setDate($this->getNextPolicy()->getStart());
                } else {
                    $discount->setDate(clone $dateNotNull);
                }
                $discount->setNotes(sprintf(
                    '%0.2f salva / %0.2f so-sure marketing from pot reward (%s)',
                    $this->getStandardPotValue(),
                    $this->getPromoPotValue(),
                    $this->getPolicyNumber()
                ));
                $this->getNextPolicy()->addPayment($discount);
            } else {
                // No cashback requested but also no renewal
                // so money was in the pot but user has completely ignored
                // create a cashback entry and try to find the user
                $cashback = new Cashback();
                $cashback->setDate(clone $dateNotNull);
                $cashback->setStatus(Cashback::STATUS_MISSING);
                $cashback->setAmount($this->getPotValue());
                $this->setCashback($cashback);
            }
        } else {
            // 0 pot value
            if ($this->hasCashback() && $this->getCashback()) {
                // If there's no money in the pot, then someone has claimed - so cashback is rejected
                $this->getCashback()->setStatus(Cashback::STATUS_CLAIMED);
                $this->getCashback()->setDate(clone $dateNotNull);
            }
        }

        if ($this->isRenewalPending() || $this->isRenewed()) {
        //if ($this->isRenewed()) {
            foreach ($this->getAcceptedConnections() as $connection) {
                if ($connection instanceof StandardConnection &&
                    ($connection->getSourcePolicy()->isActive() ||
                        $connection->getSourcePolicy()->getStatus() == Policy::STATUS_RENEWAL)
                ) {
                    $connection->setLinkedPolicyRenewal($this->getNextPolicy());
                } elseif ($connection instanceof RenewalConnection &&
                    !$connection->getSourcePolicy()->isActive()
                ) {
                    $connection->setLinkedPolicy($this->getNextPolicy());
                }
            }
        } else {
            foreach ($this->getStandardConnections() as $connection) {
                if ($inversedConnection = $connection->findInversedConnection()) {
                    $inversedConnection->prorateValue($date);
                    $inversedConnection->getSourcePolicy()->updatePotValue();
                    // listener on connection will notify user
                }
            }
        }
    }

    /**
     * Fully Expire the policy itself. Claims are no longer allowed against the policy.
     */
    public function fullyExpire(\DateTime $date = null)
    {
        if ($date == null) {
            $date = \DateTime::createFromFormat('U', time());
        }
        /** @var \DateTime $dateNotNull */
        $dateNotNull = $date;

        if ($date < $this->getEnd()) {
            throw new \Exception('Unable to expire a policy prior to its end date');
        }

        if (!in_array($this->getStatus(), [self::STATUS_EXPIRED_CLAIMABLE, self::STATUS_EXPIRED_WAIT_CLAIM])) {
            throw new \Exception('Unable to fully expire a policy if status is not expired-claimable or wait-claim');
        }

        // If a claim is currently being processed whilst expiration is occurring, the pot might be out of sync
        // especially if manually updating claim status & then processing
        // avoid running until the claim is processed
        if ($this->hasUnprocessedMonetaryClaim() || $this->hasUnprocessedMonetaryNetworkClaim()) {
            throw new \Exception(sprintf(
                'There is an unprocessed monetary claim (or network claim) for policy %s (timing issue?)',
                $this->getId()
            ));
        }

        // If a user themselves already has successfully claimed, then their pot is already 0,
        // so there is no need to wait on an open claim to complete as will not impact on pot rewards
        if (!$this->hasMonetaryClaimed() && ($this->hasOpenClaim() || $this->hasOpenNetworkClaim())) {
            // if already set, avoid setting again as might trigger db logging
            if ($this->getStatus() == self::STATUS_EXPIRED_WAIT_CLAIM) {
                return;
            }
            $this->setStatus(self::STATUS_EXPIRED_WAIT_CLAIM);

            // we want the pot value up to date for email about delay
            $this->updatePotValue();
            if ($this->hasCashback() && $this->getCashback()) {
                $this->getCashback()->setAmount($this->getPotValue());
                $this->getCashback()->setDate(clone $dateNotNull);
            }

            return;
        }

        $this->setStatus(Policy::STATUS_EXPIRED);

        $this->updatePotValue();

        $promoPotReward = $this->getPaymentByType(SoSurePotRewardPayment::class);
        $potReward = $this->getPaymentByType(PotRewardPayment::class);

        // Promo pot reward
        $promoPotValue = 0 - $this->getPromoPotValue();
        if ($promoPotReward && !$this->areEqualToTwoDp($promoPotReward->getAmount(), $promoPotValue)) {
            // pot changed (due to claim) - issue refund if applicable
            $reward = new SoSurePotRewardPayment();
            $reward->setDate(clone $dateNotNull);
            $reward->setAmount($this->toTwoDp($promoPotValue - $promoPotReward->getAmount()));
            $reward->setNotes('Adjustment to Pot as claim was settled');
            $this->addPayment($reward);
        }

        // Standard pot reward
        $standardPotValue = 0 - ($this->getStandardPotValue());
        if ($potReward && !$this->areEqualToTwoDp($potReward->getAmount(), $standardPotValue)) {
            // pot changed (due to claim) - issue refund if applicable
            $reward = new PotRewardPayment();
            $reward->setDate(clone $dateNotNull);
            $reward->setAmount($this->toTwoDp($standardPotValue - $potReward->getAmount()));
            $reward->setNotes('Adjustment to Pot as claim was settled');
            $this->addPayment($reward);
        }

        // Ensure cashback has the correct amount
        if ($this->hasCashback() && $this->getCashback()) {
            $this->getCashback()->setAmount($this->getPotValue());
            $this->getCashback()->setDate(clone $dateNotNull);
        }

        if ($this->getNextPolicy()) {
            $discount = $this->getNextPolicy()->getPaymentByType(PolicyDiscountPayment::class);
            if ($discount && !$this->areEqualToTwoDp($discount->getAmount(), $this->getPotValue())) {
                // pot changed (due to claim) - issue refund if applicable
                $adjustedDiscount = new PolicyDiscountPayment();
                $adjustedDiscount->setDate(clone $dateNotNull);
                $adjustedDiscount->setAmount($this->toTwoDp($this->getPotValue() - $discount->getAmount()));
                // @codingStandardsIgnoreStart
                $adjustedDiscount->setNotes(sprintf(
                    'Adjust previous discount. Split should be %0.2f salva / %0.2f so-sure marketing from pot reward (%s)',
                    $this->getStandardPotValue(),
                    $this->getPromoPotValue(),
                    $this->getPolicyNumber()
                ));
                // @codingStandardsIgnoreEnd
                $this->getNextPolicy()->addPayment($adjustedDiscount);
                $this->getNextPolicy()->getPremium()->setAnnualDiscount($this->getPotValue());
            }
        }
    }


    /**
     * Gives you this policy's underwriter's timezone.
     * @return \DateTimeZone the timezone the underwriter operates in.
     */
    abstract public function getUnderwriterTimeZone();

    /**
     * Gives you the policy's underwriter.
     * @return string the name of the underwriter.
     */
    abstract public function getUnderwriterName();

    /**
     * Get the current max connection for this policy
     * @return mixed
     */
    abstract public function getMaxConnections(\DateTime $date = null);

    /**
     * Get the absolute limit of the max connections based on premium (ignoring claims, etc)
     * @return mixed
     */
    abstract public function getMaxConnectionsLimit(\DateTime $date = null);

    abstract public function getMaxPot();

    abstract public function getConnectionValue();
    abstract public function getPolicyNumberPrefix();
    abstract public function getAllowedConnectionValue(\DateTime $date = null);
    abstract public function getAllowedPromoConnectionValue(\DateTime $date = null);
    abstract public function getTotalConnectionValue(\DateTime $date = null);
    abstract public function isSameInsurable(Policy $policy);
    abstract public function setPolicyDetailsForPendingRenewal(
        Policy $policy,
        \DateTime $startDate,
        PolicyTerms $terms
    );
    abstract public function setPolicyDetailsForRepurchase(Policy $policy, \DateTime $startDate);

    public function isPotCompletelyFilled()
    {
        if (!$this->isPolicy()) {
            throw new \Exception('Not yet a policy - does not make sense to check this now.');
        }
        return $this->areEqualToFourDp($this->getPotValue(), $this->getMaxPot());
    }

    public function getCurrentConnectionValues()
    {
        $now = \DateTime::createFromFormat('U', time());
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
        $now = \DateTime::createFromFormat('U', time());
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

    public function isFullyPaid(\DateTime $date = null)
    {
        return $this->areEqualToTwoDp(0, $this->getRemainderOfPolicyPrice($date));
    }

    /**
     * @param \DateTime|null $date
     * @param boolean|null   $applyPartialDiscounts true to apply, false to completely ignore,
     *                                              and null to include in total
     * @return float
     */
    public function getTotalSuccessfulPayments(\DateTime $date = null, $applyPartialDiscounts = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $totalPaid = 0;
        $numberOfPayments = 0;
        $totalDiscount = 0;
        foreach ($this->getSuccessfulPayments() as $payment) {
            // payment applied in the future - ignore
            if ($payment->getDate() > $date) {
                continue;
            }
            if ($payment instanceof \AppBundle\Document\Payment\PolicyDiscountPayment &&
                $applyPartialDiscounts !== null) {
                $totalDiscount += $payment->getAmount();
            } else {
                $totalPaid += $payment->getAmount();
                $numberOfPayments++;
            }
        }
        if ($totalDiscount > 0 && $applyPartialDiscounts) {
            $totalPaid += $this->toTwoDp($numberOfPayments * $totalDiscount / 12);
        }

        return $totalPaid;
    }

    public function getTotalSuccessfulUserPayments(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $totalPaid = 0;
        foreach ($this->getSuccessfulUserPayments() as $payment) {
            if ($payment->getDate() <= $date) {
                $totalPaid += $payment->getAmount();
            }
        }

        return $totalPaid;
    }

    public function getTotalSuccessfulStandardPayments($includeDiscounts = false, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $totalPaid = 0;
        foreach ($this->getSuccessfulStandardPayments() as $payment) {
            if (!$payment->isDiscount() || ($includeDiscounts && $payment->isDiscount())) {
                if ($payment->getDate() <= $date) {
                    $totalPaid += $payment->getAmount();
                }
            }
        }

        return $totalPaid;
    }

    public function getTotalExpectedPaidToDate(\DateTime $date = null, $firstDayIsUnpaid = false)
    {
        if (!$this->isPolicy() || !$this->getStart()) {
            return null;
        }

        if (!$date) {
            $date = new \DateTime('now', SoSure::getSoSureTimezone());
        }
        //$date->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $date = $this->adjustDayForBilling($date, true);

        $expectedPaid = 0;
        if ($this->getPremiumPlan() == self::PLAN_YEARLY) {
            $expectedPaid = $this->getPremium()->getAdjustedYearlyPremiumPrice();
        } elseif ($this->getPremiumPlan() == self::PLAN_MONTHLY) {
            $months = $this->dateDiffMonths($date, $this->getBilling(), true, $firstDayIsUnpaid);
            if ($months > 12) {
                $months = 12;
            }

            /*
            print PHP_EOL;
            print $date->format(\DateTime::ATOM) . PHP_EOL;
            print $this->getBilling()->format(\DateTime::ATOM) . PHP_EOL;
            print $months . PHP_EOL;
            */

            $expectedPaid = $this->getPremium()->getAdjustedStandardMonthlyPremiumPrice() * $months;
        } else {
            throw new \Exception('Unknown premium plan');
        }

        return $expectedPaid;
    }

    /**
     * Gives you the amount of money that the user must pay for this policy if it is unpaid.
     * @param \DateTime $date is the date at which this sum must be current.
     * @return float the amount owed.
     */
    public function getOutstandingPremiumToDateWithReschedules($date)
    {
        $amount = $this->getOutstandingPremiumToDate($date);
        if ($this->greaterThanZero($amount)) {
            return $amount;
        }
        foreach ($this->getScheduledPayments() as $scheduledPayment) {
            if ($scheduledPayment->getType() == ScheduledPayment::TYPE_RESCHEDULED &&
                $scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED
            ) {
                $amount += $scheduledPayment->getAmount();
            }
        }
        return $amount;
    }

    public function getOutstandingPremiumToDate(
        \DateTime $date = null,
        $allowNegative = false,
        $firstDayIsUnpaid = false
    ) {
        if (!$this->isPolicy()) {
            return null;
        }

        $totalPaid = $this->getTotalSuccessfulPayments($date, false);
        $expectedPaid = $this->getTotalExpectedPaidToDate($date, $firstDayIsUnpaid);

        $diff = $expectedPaid - $totalPaid;
        // print sprintf("paid %f expected %f diff %f\n", $totalPaid, $expectedPaid, $diff);
        if (!$allowNegative && $diff < 0) {
            return 0;
        }

        return $diff;
    }

    public function getOutstandingUserPremiumToDate(\DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return null;
        }

        $totalPaid = $this->getTotalSuccessfulUserPayments($date);
        $expectedPaid = $this->getTotalExpectedPaidToDate($date);

        $diff = $expectedPaid - $totalPaid;
        //print sprintf("paid %f expected %f diff %f\n", $totalPaid, $expectedPaid, $diff);
        if ($diff < 0) {
            return 0;
        }

        return $diff;
    }

    public function getRemainderOfPolicyPrice(\DateTime $date = null)
    {
        $totalPaid = $this->getTotalSuccessfulPayments($date);
        $yearlyPremium = $this->getPremium()->getAdjustedYearlyPremiumPrice();
        $diff = $yearlyPremium - $totalPaid;
        if ($diff < 0) {
            return 0;
        }

        return $this->toTwoDp($diff);
    }

    public function canInvite()
    {
        if ($this->isPotCompletelyFilled()) {
            return false;
        }
        if ($this->hasMonetaryClaimed()) {
            return false;
        }
        if (!$this->isActive()) {
            return false;
        }

        return true;
    }

    public function hasCorrectPolicyStatus(\DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return null;
        }
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $ignoredStatuses = [
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_EXPIRED_CLAIMABLE,
            self::STATUS_EXPIRED_WAIT_CLAIM,
            self::STATUS_MULTIPAY_REJECTED,
            self::STATUS_MULTIPAY_REQUESTED,
            self::STATUS_PENDING_RENEWAL,
        ];

        // mostly concerned with Active vs Unpaid
        if (in_array($this->getStatus(), $ignoredStatuses)) {
            return null;
        }

        if ($this->getStatus() == self::STATUS_PENDING) {
            return false;
        }

        $bankAccount = $this->getPolicyOrUserBacsBankAccount();
        // Judo policies get a pass on days with scheduled payments.
        if (!$bankAccount) {
            $scheduledPayments = $this->getScheduledPayments();
            foreach ($scheduledPayments as $scheduledPayment) {
                if ($this->isSameDay($scheduledPayment->getScheduled(), $date)) {
                    return true;
                }
            }
        }
        $fourteenDaysAgo = (clone $date)->sub(new \DateInterval("P14D"));
        if ($this->getPolicyTerms()->isPicSureRequired() && $this->getStart() >= $fourteenDaysAgo) {
            return $this->getStatus() == self::STATUS_PICSURE_REQUIRED;
        } elseif ($this->getStatus() == self::STATUS_RENEWAL) {
            return $this->getStart() > $date;
        } elseif ($this->isPolicyPaidToDate($date, true, false, true)) {
            return $this->getStatus() == self::STATUS_ACTIVE;
        } elseif ($bankAccount && ($bankAccount->isMandateInProgress() ||
                ($bankAccount->isMandateSuccess() && $bankAccount->isBeforeInitialNotificationDate()))) {
            return $this->getStatus() == self::STATUS_ACTIVE;
        } else {
            return in_array($this->getStatus(), [self::STATUS_UNPAID]);
        }
    }

    public function isPolicyPaidToDate(
        \DateTime $date = null,
        $includePendingBacs = false,
        $firstDayIsUnpaid = false,
        $includeFuturePayments = false
    ) {
        if (!$this->isPolicy()) {
            return null;
        }
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if ($includeFuturePayments) {
            $futureDate = clone $date;
            $futureDate = $this->endOfDay($this->getNextBusinessDay($futureDate));
            $totalPaid = $this->getTotalSuccessfulPayments($futureDate, true);
        } else {
            $totalPaid = $this->getTotalSuccessfulPayments($date, true);
        }
        if ($includePendingBacs) {
            $totalPaid += $this->getPendingBacsPaymentsTotal();
        }
        $expectedPaid = $this->getTotalExpectedPaidToDate($date, $firstDayIsUnpaid);

        // >= doesn't quite allow for minor float differences
        $result = $this->areEqualToTwoDp($expectedPaid, $totalPaid) || $totalPaid > $expectedPaid;
        // print sprintf("%f =? %f return %s%s", $totalPaid, $expectedPaid, $result ? 'true': 'false', PHP_EOL);

        return $result;
    }

    public function hasScheduledPaymentInCurrentMonth(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $nextPayment = $this->getNextScheduledPayment() ? $this->getNextScheduledPayment()->getScheduled() : false;

        if ($nextPayment) {
            if ($nextPayment->format('m Y') == $date->format('m Y')) {
                return true;
            }
        }

        return false;
    }

    public function hasScheduledPaymentOnDate(\DateTime $date)
    {
        if (!$date) {
            throw new \InvalidArgumentException("A date must be provided to look up scheduled payment by date");
        }

        $scheduledPayments = $this->getActiveScheduledPayments();
        foreach ($scheduledPayments as $scheduledPayment) {
            if ($scheduledPayment->getScheduled()->format('Ymd') === $date->format('Ymd')) {
                return true;
            }
        }
        return false;
    }

    public function getOutstandingScheduledPaymentsAmount()
    {
        $scheduledPayments = $this->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);

        return ScheduledPayment::sumScheduledPaymentAmounts($scheduledPayments);
    }

    public function isUnpaidBacs()
    {
        if ($this->getStatus() != self::STATUS_UNPAID) {
            return null;
        }

        return $this->hasPolicyOrUserBacsPaymentMethod();
    }

    public function isUnpaidCloseToExpirationDate(\DateTime $date = null)
    {
        if ($this->getStatus() != self::STATUS_UNPAID) {
            return null;
        }

        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        // payment on day 0
        // reschedule on 7, 14, 21
        // max 31-21 = 10
        $diff = $date->diff($this->getPolicyExpirationDate());
        $closeToExpiration = $diff->days <= 10 && $diff->invert == 0;

        return $closeToExpiration;
    }

    public function arePolicyScheduledPaymentsCorrect(
        $verifyBillingDay = true,
        \DateTime $date = null,
        $skipIfAlmostCancelled = false
    ) {
        if (!in_array($this->getStatus(), [
            self::STATUS_ACTIVE,
            self::STATUS_UNPAID,
            self::STATUS_PENDING,
            self::STATUS_PICSURE_REQUIRED
        ])) {
            return null;
        }

        $scheduledPayments = $this->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED);

        if ($skipIfAlmostCancelled) {
            // once all the payment rescheduling has finished, there is a period of a few days where the scheduled
            // payments will not match; if this is the case, there is no need to alert on it
            $cancellationDate = clone $this->getPolicyExpirationDate($date);
            if ($this->hasPreviousPolicy()) {
                // 4 payment retries - 0, 7, 14, 21; should be 30 days unpaid before cancellation
                // 9 days diff + 2 on either side
                if ($this->hasPolicyOrPayerOrUserJudoPaymentMethod()) {
                    $cancellationDate = $cancellationDate->sub(new \DateInterval('P11D'));
                } elseif ($this->hasPolicyOrUserBacsPaymentMethod()) {
                    // currently not rescheduling with bacs, 15 days to avoid some incorrect notifications
                    $cancellationDate = $cancellationDate->sub(new \DateInterval('P15D'));
                } elseif ($this->hasCheckoutPaymentMethod()) {
                    $cancellationDate = $cancellationDate->sub(new \DateInterval('P11D'));
                }
            } else {
                // 4 payment retries - 7, 14, 21, 28; should be 30 days unpaid before cancellation
                // 2 days diff + 2 on either side
                if ($this->hasPolicyOrPayerOrUserJudoPaymentMethod()) {
                    $cancellationDate = $cancellationDate->sub(new \DateInterval('P4D'));
                } elseif ($this->hasPolicyOrUserBacsPaymentMethod()) {
                    // currently not rescheduling with bacs, 15 days to avoid some incorrect notifications
                    $cancellationDate = $cancellationDate->sub(new \DateInterval('P15D'));
                } elseif ($this->hasCheckoutPaymentMethod()) {
                    $cancellationDate = $cancellationDate->sub(new \DateInterval('P4D'));
                }
            }
            if ($cancellationDate <= $date) {
                return null;
            }
        }

        // All Scheduled day must match the billing day
        if ($verifyBillingDay) {
            $initialNotificationDate = null;
            $bankAccount = $this->getPolicyOrUserBacsBankAccount();
            if ($bankAccount) {
                $initialNotificationDate = $bankAccount->getInitialNotificationDate();
            }
            foreach ($scheduledPayments as $scheduledPayment) {
                /** @var ScheduledPayment $scheduledPayment */

                // for bacs, we may have a different scheduled payment for the first notification date
                $initialNotificationDateDiff = $initialNotificationDate != null ?
                    $initialNotificationDate->diff($scheduledPayment->getScheduled()) :
                    null;
                if ($initialNotificationDateDiff && $initialNotificationDateDiff->d == 0) {
                    continue;
                }

                if ($scheduledPayment->hasCorrectBillingDay() === false) {
                    /*
                    $diff = $scheduledPayment->getScheduled()->diff($this->getBilling());
                    print sprintf(
                        "%s%s %s %s%s",
                        PHP_EOL,
                        $scheduledPayment->getScheduled()->format(\DateTime::ATOM),
                        $this->getBilling()->format(\DateTime::ATOM),
                        json_encode($diff),
                        PHP_EOL
                    );
                    */

                    return false;
                }
            }
        }

        $totalScheduledPayments = ScheduledPayment::sumScheduledPaymentAmounts($scheduledPayments);

        // Pending bacs payments should be thought of as successful and thereby reduce the outstanding premium
        $outstandingPremium = $this->getOutstandingPremium() - $this->getPendingBacsPaymentsTotal();

        // generally would expect the outstanding premium to match the scheduled payments
        // however, if unpaid and either past the point where rescheduled payments are taken or using bacs
        // then would expect the scheduled payments to be missing 1 monthly premium
        if ($this->areEqualToTwoDp($outstandingPremium, $totalScheduledPayments)) {
            return true;
        } elseif ($this->isUnpaidCloseToExpirationDate($date) || $this->isUnpaidBacs()) {
            $standardTotal = $totalScheduledPayments + $this->getPremium()->getAdjustedStandardMonthlyPremiumPrice();
            $finalTotal = $totalScheduledPayments + $this->getPremium()->getAdjustedFinalMonthlyPremiumPrice();
            if ($this->areEqualToTwoDp(
                $outstandingPremium,
                $standardTotal
            )) {
                return true;
            } elseif ($this->areEqualToTwoDp(
                $outstandingPremium,
                $finalTotal
            )) {
                return true;
            } elseif ($this->areEqualToTwoDp(
                $outstandingPremium,
                $standardTotal - $this->getScheduledPaymentRefundAmount()
            )) {
                return true;
            } elseif ($this->areEqualToTwoDp(
                $outstandingPremium,
                $finalTotal - $this->getScheduledPaymentRefundAmount()
            )) {
                return true;
            }
        }

        /*
        print $totalScheduledPayments . PHP_EOL;
        print $this->getOutstandingPremium() . PHP_EOL;
        print $this->getPremium()->getYearlyPremiumPrice() . PHP_EOL;
        print $this->getPremiumPaid() . PHP_EOL;
        */

        return false;
    }

    public function getClaimsText()
    {
        $data = Claim::sumClaims($this->getClaims());

        if ($data['total'] == 0) {
            return '-';
        }

        $text = '';
        if ($data['fnol'] > 0) {
            $text = sprintf(
                '%s<span title="FNOL" class="fa fa-clock-o">%s</span> ',
                $text,
                $data['fnol']
            );
        }
        if ($data['in-review'] + $data['submitted'] > 0) {
            $text = sprintf(
                '%s<span title="Submitted & In Review" class="fa fa-question">%s</span> ',
                $text,
                $data['in-review'] + $data['submitted']
            );
        }
        if ($data['approved'] + $data['settled'] > 0) {
            $text = sprintf(
                '%s<span title="Approved & Settled" class="fa fa-thumbs-up">%s</span> ',
                $text,
                $data['approved'] + $data['settled']
            );
        }
        if ($data['declined'] + $data['withdrawn'] > 0) {
            $text = sprintf(
                '%s<span title="Declined & Withdrawn" class="fa fa-thumbs-down">%s</span> ',
                $text,
                $data['declined'] + $data['withdrawn']
            );
        }
        if (count($this->getLinkedClaims()) > 0) {
            $text = sprintf(
                '%s<span title="Linked (e.g. policy upgrade) class="fa fa-link">%s</span> ',
                $text,
                count($this->getLinkedClaims())
            );
        }
        if (count($this->getNetworkClaims()) > 0) {
            $text = sprintf(
                '%s<span title="Network claims (any status)" class="fa fa-group">%s</span> ',
                $text,
                count($this->getNetworkClaims())
            );
        }

        return $text;
    }

    public function getBadDebtAmount()
    {
        if (!$this->isCancelledAndPaymentOwed()) {
            return 0;
        }

        if (!$this->getPolicyTerms()->isFullReImbursementEnabled()) {
            return $this->getOutstandingPremium();
        }

        $amount = 0;
        foreach ($this->getApprovedClaims(true, true) as $claim) {
            /** @var Claim $claim */
            $amount += $claim->getTotalIncurred();
        }

        return $amount;
    }

    public function isCancelledAndPaymentOwed()
    {
        if (!$this->getPremium()) {
            return false;
        }
        if (!$this->isFullyPaid() &&
            count($this->getApprovedClaims(true, true)) > 0 &&
            $this->getStatus() == self::STATUS_CANCELLED &&
            $this->getCancelledReason() != self::CANCELLED_UPGRADE) {
            foreach ($this->getApprovedClaims(true, true) as $claim) {
                if ($claim->getLinkedPolicy()) {
                    // if this is the linked policy, then its automatically a cancelled w/payment owed
                    if ($claim->getLinkedPolicy()->getId() == $this->getId()) {
                        // print 'same' . PHP_EOL;
                        return true;
                    } elseif (!$claim->getLinkedPolicy()->isActive()) {
                        // there was a linked policy, but its not active, so again ists a cancelled w/payment owed
                        // print 'inactive' . PHP_EOL;
                        return true;
                    }
                } else {
                    // if there isn't a linked policy for one of the claims, then the policy must be this one
                    // e.g. automatically a cancelled w/payment owed
                    // print 'unlinked' . PHP_EOL;
                    return true;
                }
            }
        }

        return false;
    }

    public function getUnpaidReason(\DateTime $date = null)
    {
        if ($this->getStatus() != self::STATUS_UNPAID || !$this->isPolicy()) {
            return null;
        }

        $outstandingPremium = $this->getOutstandingPremiumToDate($date);
        $nextScheduledPayment = $this->getNextScheduledPayment();
        $lastPaymentCredit = $this->getLastPaymentCredit();
        $lastPaymentInProgress = false;
        $lastPaymentFailure = false;
        if ($this->hasPolicyOrUserBacsPaymentMethod()) {
            if ($nextScheduledPayment && $nextScheduledPayment->getScheduled() <= $this->now()) {
                $lastPaymentInProgress = $nextScheduledPayment->getStatus() == ScheduledPayment::TYPE_SCHEDULED;
            } elseif ($lastPaymentCredit && $lastPaymentCredit instanceof BacsPayment) {
                /** @var BacsPayment $lastPaymentCredit */
                $lastPaymentInProgress = $lastPaymentCredit->inProgress();
                $lastPaymentFailure = $lastPaymentCredit->getStatus() == BacsPayment::STATUS_FAILURE;
            }

            $bankAccount = $this->getPolicyOrUserBacsBankAccount();
            if ($bankAccount && $bankAccount->isMandateInProgress()) {
                return self::UNPAID_BACS_MANDATE_PENDING;
            } elseif ($bankAccount && $bankAccount->isMandateInvalid()) {
                return self::UNPAID_BACS_MANDATE_INVALID;
            } elseif ($lastPaymentInProgress) {
                return self::UNPAID_BACS_PAYMENT_PENDING;
            } elseif ($lastPaymentFailure) {
                return self::UNPAID_BACS_PAYMENT_FAILED;
            } elseif ($outstandingPremium > 0) {
                // we're unpaid with some premium due - the mandate is successful and the last bacs payment
                // was either not present, or was successful
                return self::UNPAID_BACS_PAYMENT_MISSING;
            }

            return self::UNPAID_BACS_UNKNOWN;
        } elseif ($this->hasPolicyOrPayerOrUserJudoPaymentMethod()) {
            if ($lastPaymentCredit &&
                ($lastPaymentCredit instanceof JudoPayment || $lastPaymentCredit instanceof CheckoutPayment)) {
                $lastPaymentFailure = !$lastPaymentCredit->isSuccess();
            }

            $judoPaymentMethod = $this->getPolicyOrPayerOrUserJudoPaymentMethod();
            if ($judoPaymentMethod && $judoPaymentMethod->isCardExpired($date)) {
                return self::UNPAID_CARD_EXPIRED;
            } elseif ($lastPaymentFailure) {
                return self::UNPAID_CARD_PAYMENT_FAILED;
            } elseif ($outstandingPremium > 0) {
                // we're unpaid with some premium due - the card is not expired and the last judo payment
                // was either not present, or was successful
                return self::UNPAID_CARD_PAYMENT_MISSING;
            }

            return self::UNPAID_CARD_UNKNOWN;
        } elseif ($this->hasCheckoutPaymentMethod()) {
            if ($lastPaymentCredit &&
                ($lastPaymentCredit instanceof JudoPayment || $lastPaymentCredit instanceof CheckoutPayment)) {
                $lastPaymentFailure = !$lastPaymentCredit->isSuccess();
            }

            $checkoutPaymentMethod = $this->getCheckoutPaymentMethod();
            if ($checkoutPaymentMethod && $checkoutPaymentMethod->isCardExpired($date)) {
                return self::UNPAID_CARD_EXPIRED;
            } elseif ($lastPaymentFailure) {
                return self::UNPAID_CARD_PAYMENT_FAILED;
            } elseif ($outstandingPremium > 0) {
                // we're unpaid with some premium due - the card is not expired and the last judo payment
                // was either not present, or was successful
                return self::UNPAID_CARD_PAYMENT_MISSING;
            }

            return self::UNPAID_CARD_UNKNOWN;
        } elseif (!$this->getPolicyOrUserPaymentMethod()) {
            return self::UNPAID_PAYMENT_METHOD_MISSING;
        }

        return self::UNPAID_UNKNOWN;
    }

    public function getSupportWarnings()
    {
        // @codingStandardsIgnoreStart
        $warnings = [];
        // @codingStandardsIgnoreEnd
        if ($this->hasOpenClaim(true)) {
            // @codingStandardsIgnoreStart
            $warnings[] = sprintf('Policy has an open claim. Check & Notify Davies prior to cancellation (do not cancel if phone was shipped).');
            // @codingStandardsIgnoreEnd
        }
        if ($this->hasMonetaryClaimed()) {
            $warnings[] = sprintf('Policy has had a sucessful claim. Do NOT allow cancellation.');
        }
        if ($this->hasSuspectedFraudulentClaim()) {
            $warnings[] = sprintf('Policy has had a suspected fraudulent claim. Do NOT allow policy upgrade.');
        }

        if ($this instanceof PhonePolicy) {
            /** @var PhonePolicy $phonePolicy */
            $phonePolicy = $this;

            if ($phonePolicy->hasInvalidImei()) {
                // @codingStandardsIgnoreStart
                $warnings[] = 'Policy IMEI is unknown and should be requested. Proof of purchase is required prior to claim approval.';
                // @codingStandardsIgnoreEnd
            }
        }

        return $warnings;
    }

    public function getClaimsWarnings()
    {
        $warnings = [];
        if (!$this->isPolicyPaidToDate() || $this->getStatus() == self::STATUS_UNPAID) {
            $warnings[] = sprintf('Policy is NOT paid to date - Policy must be paid to date prior to approval');
        }

        if (in_array($this->getStatus(), [
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_EXPIRED_WAIT_CLAIM
        ])) {
            $warnings[] = sprintf('Policy is %s - DO NOT ALLOW CLAIM', $this->getStatus());
        }

        if (in_array($this->getStatus(), [self::STATUS_EXPIRED_CLAIMABLE])) {
            $warnings[] = sprintf(
                'Policy is expired - ONLY ALLOW IF LOSS WAS BEFORE %s',
                $this->getEnd()->format(\DateTime::ATOM)
            );
        }

        if ($this->hasOpenClaim(true)) {
            $warnings[] = sprintf('Policy has an open claim, but Recipero (ClaimsCheck) has not been run.');
        }

        if (!$this->isAdditionalClaimLostTheftApprovedAllowed()) {
            // @codingStandardsIgnoreStart
            $warnings[] = sprintf('Policy already has 2 lost/theft claims. No further lost/theft claims are allowed, however, allow any in-progress FNOL claims');
            // @codingStandardsIgnoreEnd
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

        if ($this->hasRequestedCancellation()) {
            $warnings[] = sprintf(
                'User has requested (%s) that this policy be cancelled.',
                $this->getRequestedCancellation()->format(\DateTime::ATOM)
            );
        }

        if ($this->getLatestFnolClaim()) {
            $warnings[] =
                'Policy has a FNOL claim, but not yet submitted and so required documents may not yet be uploaded.';
        }

        foreach ($this->getClaims() as $claim) {
            /** @var Claim $claim */
            if ($claim->warnCrimeRef()) {
                $warnings[] = sprintf('Claim %s has a crime reference number that is not valid', $claim->getNumber());
            }
        }

        if ($this instanceof PhonePolicy) {
            /** @var PhonePolicy $phonePolicy */
            $phonePolicy = $this;

            if ($phonePolicy->hasInvalidImei()) {
                // @codingStandardsIgnoreStart
                $warnings[] = 'Policy IMEI is unknown and should be requested. Proof of purchase is required prior to claim approval.';
                // @codingStandardsIgnoreEnd
            }

            $foundSerial = false;
            $mismatch = false;
            foreach ($phonePolicy->getCheckmendCertsAsArray(false) as $key => $cert) {
                if (isset($cert['certId']) && $cert['certId'] == 'serial') {
                    $foundSerial = true;
                    if (!isset($cert['response']['makes']) ||
                        count($cert['response']['makes']) == 0
                    ) {
                        $mismatch = true;
                    }
                }
            }

            if (!$foundSerial || $mismatch) {
                // @codingStandardsIgnoreStart
                $warnings[] = sprintf(
                    'Recipero was unable to verify if the phone insured matches the imei given. Secondary verification of the type of phone may be advisable.'
                );
                // @codingStandardsIgnoreEnd
            }
        }

        return $warnings;
    }

    public function isPotValueCorrect()
    {
        return $this->areEqualToTwoDp($this->getPotValue(), $this->calculatePotValue()) &&
            $this->areEqualToTwoDp($this->getPromoPotValue(), $this->calculatePotValue(true));
    }

    public function getCurrentExcess()
    {
        if ($this->getPremium()) {
            return $this->getPremium()->getExcess();
        } else {
            return null;
        }
    }

    /**
     * Sets commission the normal way and then subtracts it from 0.
     * @param Payment        $payment       is the payment that is going to get refund commission.
     * @param boolean        $allowFraction is whether to allow fractional payments to calculate their commission.
     * @param \DateTime|null $date          is the date at which the calculation should be accurate, null meaning now.
     */
    public function setCommissionInverted($payment, $allowFraction = false, \DateTime $date = null)
    {
        $this->setCommission($payment, $allowFraction, $date);
        $payment->setCoverholderCommission(0 - $payment->getCoverholderCommission());
        $payment->setBrokerCommission(0 - $payment->getBrokerCommission());
        $payment->setTotalCommission(0 - $payment->getTotalCommission());
    }

    /**
     * Sets the commission of a payment belonging to this policy because the policy knows the coverholder and their
     * commission rules.
     * @param Payment        $payment       is the payment that we are setting the commission for.
     * @param boolean        $allowFraction is whether to allow fractional payments and calculate fractional commission
     *                                      for them.
     * @param \DateTime|null $date          is the date at which pro rata things will be calculated.
     */
    abstract public function setCommission($payment, $allowFraction = false, \DateTime $date = null);

    /**
     * Gives you the total amount of commission this policy should pay.
     * @return float the amount.
     */
    abstract public function getYearlyTotalCommission(): float;

    /**
     * Gives you the yearly coverholder commission for this policy.
     * @return float the amount.
     */
    abstract public function getYearlyCoverholderCommission(): float;

    /**
     * Gives you the yearly broker commission for this policy.
     * @return float the amount.
     */
    abstract public function getYearlyBrokerCommission(): float;

    /**
     * Calculates the amount of commission that this policy should have paid currently based on their underwriter's
     * rules.
     * @param \DateTime|null $date is the date at which this amount should be valid, with null meaning right now.
     * @return float the expected amount.
     */
    abstract public function getExpectedCommission(\DateTime $date = null);

    public function hasCorrectCommissionPayments(
        \DateTime $date = null,
        $allowedVariance = 0,
        $excludeChargebacks = false
    ) {
        $expectedCommission = $this->getExpectedCommission($date);

        if ($excludeChargebacks) {
            // If there are chargebacks, exclude from the expected commission
            $excludedPayments = $this->getPaymentsByTypes([ChargebackPayment::class, BacsIndemnityPayment::class]);
            $excludedPaymentsTotal = Payment::sumPayments($excludedPayments, false);
            // as refunds, should be negative amount, so + is correct operation
            $expectedCommission = $expectedCommission + $excludedPaymentsTotal['totalCommission'];
        }

        /*
        print $numPayments . PHP_EOL;
        print $expectedCommission . PHP_EOL;
        print $this->getTotalCommissionPaid() . PHP_EOL;
        */

        $diff = abs($this->getTotalCommissionPaid(null, true) - $expectedCommission);

        return $this->areEqualToTwoDp($diff, $allowedVariance) ||  $diff < $allowedVariance;
    }

    public function getPremiumPayments()
    {
        return [
            'paid' => $this->eachApiArray($this->getSuccessfulPayments()),
            'scheduled' => $this->eachApiArray($this->getAllScheduledPayments(ScheduledPayment::STATUS_SCHEDULED)),
        ];
    }

    public function hasUnconnectedUserPolicies()
    {
        return count($this->getUnconnectedUserPolicies()) > 0;
    }

    public function getUnconnectedUserPolicies()
    {
        $unconnectedPolicies = [];
        if (!$this->isActive()) {
            return $unconnectedPolicies;
        }
        foreach ($this->getUser()->getValidPolicies() as $policy) {
            if ($policy->getId() != $this->getId()) {
                $connectionFound = false;
                foreach ($this->getStandardConnections() as $connection) {
                    $connectionFound = $connectionFound ||
                        $connection->getLinkedPolicy()->getId() == $policy->getId();
                }
                if (!$connectionFound) {
                    $unconnectedPolicies[] = $policy;
                }
            }
        }

        return $unconnectedPolicies;
    }

    public function isInRenewalTimeframe(\DateTime $date = null)
    {
        if (!$this->isPolicy()) {
            return null;
        }

        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if ($this->isActive()) {
            $diff = $this->getEnd()->diff($date);
            $notPastDate = $diff->days > 0 || ($diff->days == 0 && $diff->invert == 1);
            if ($diff->days <= self::RENEWAL_DAYS && $notPastDate) {
                return true;
            }
        }

        return false;
    }

    /**
     * Display is about displaying renewal in general, e.g. the renewal tab for the user
     * so even if they renewed, they should still see the renewal
     */
    public function displayRenewal(\DateTime $date = null)
    {
        if (!$this->canRenew($date)) {
            return false;
        }

        return true;
    }

    /**
     * Notify user (flash message, emails, etc) about renewals
     */
    public function notifyRenewal(\DateTime $date = null)
    {
        if (!$this->canRenew($date)) {
            return false;
        }

        if (!$this->getUser()->areRenewalsDesired()) {
            return false;
        }

        return !$this->isRenewed();
    }

    /**
     * Pending renewal policy is required in order to renew
     */
    public function canCreatePendingRenewal(\DateTime $date = null)
    {
        if (!$this->getUser()->canRenewPolicy($this)) {
            return false;
        }

        // Will also check policy status
        if (!$this->isInRenewalTimeframe($date)) {
            return false;
        }

        // TODO: any other reason why not to allow this policy to be renewed?
        return true;
    }

    /**
     * Can renew is for this specific policy
     * This is different than if a user is allowed to purchase an additional policy
     * although lines get blurred if a policy expires and then user wants to re-purchase
     */
    public function canRenew(\DateTime $date = null, $checkTimeframe = true)
    {
        // partial renewal policy wasn't created - never allow renewal in this case
        // not checking if already renewed deliberately as using canRenew to display
        // renewal page even if already renewed
        if (!$this->hasNextPolicy()) {
            return false;
        }

        // In case user was disallowed after pending renewal was created
        if (!$this->getUser()->canRenewPolicy($this)) {
            return false;
        }

        // In case the policy is now expired
        if ($checkTimeframe && !$this->isInRenewalTimeframe($date)) {
            return false;
        }

        return true;
    }

    public function isRenewed()
    {
        // partial renewal policy wasn't created
        if (!$this->hasNextPolicy()) {
            return false;
        }

        // Typically would expect renewal status
        // however, if checked post active, then the status would be active/unpaid
        // or various other statuses
        // however, if pending renewal or unrenewed, then policy has definitely not been renewed
        // so a safer assumption
        return !in_array($this->getNextPolicy()->getStatus(), [
            Policy::STATUS_PENDING_RENEWAL,
            Policy::STATUS_UNRENEWED,
            Policy::STATUS_DECLINED_RENEWAL,
        ]);
    }

    public function isRenewalPending()
    {
        // partial renewal policy wasn't created
        if (!$this->hasNextPolicy()) {
            return false;
        }

        return in_array($this->getNextPolicy()->getStatus(), [
            Policy::STATUS_PENDING_RENEWAL,
        ]);
    }

    public function isRenewalDeclined()
    {
        // partial renewal policy wasn't created
        if (!$this->hasNextPolicy()) {
            return false;
        }

        return in_array($this->getNextPolicy()->getStatus(), [
            Policy::STATUS_DECLINED_RENEWAL,
        ]);
    }

    public function isRenewal()
    {
        if (!$this->hasPreviousPolicy()) {
            return false;
        }

        // Typically would expect renewal status
        // however, if checked post active, then the status would be active/unpaid
        // or various other statuses
        // however, if pending renewal or unrenewed, then policy has definitely not been renewed
        // so a safer assumption
        return !in_array($this->getStatus(), [
            Policy::STATUS_PENDING_RENEWAL,
            Policy::STATUS_UNRENEWED,
        ]);
    }

    /**
     * Display is about displaying repurchase button in general
     */
    public function displayRepurchase()
    {
        if (!$this->canRepurchase()) {
            return false;
        }

        // Expired policies that are unrenewed
        if (in_array($this->getStatus(), [
            self::STATUS_EXPIRED,
            self::STATUS_EXPIRED_CLAIMABLE,
            self::STATUS_EXPIRED_WAIT_CLAIM,
        ]) && $this->hasNextPolicy() && $this->getNextPolicy()->getStatus() == self::STATUS_UNRENEWED) {
            return true;
        }

        // Cancelled policy - should account for isCancelledWithUserDeclined logic
        if ($this->getStatus() == self::STATUS_CANCELLED) {
            if (in_array($this->getCancelledReason(), [
                self::CANCELLED_COOLOFF,
                self::CANCELLED_USER_REQUESTED,
            ])) {
                return true;
            }

            // For the rare case where a policy is cancelled for another reason (e.g. unpaid)
            // but we want to allow repurchase, so we set the flag to ignore the claim
            if (count($this->getApprovedClaims()) > 0 &&
                count($this->getApprovedClaims(true, true, true)) == 0) {
                return true;
            }

            // For the rare case where a policy is cancelled for another reason (e.g. unpaid)
            // but we want to allow repurchase, so we set the flag to ignore the claim
            if (count($this->getWithdrawnDeclinedClaims()) > 0 &&
                count($this->getWithdrawnDeclinedClaims(true, true)) == 0) {
                return true;
            }

            // If user forgot to pay and doesn't have a claim we will allow re-purchase
            if (count($this->getClaims()) == 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Can the user re-purchase this specific policy
     * This is different than if a user is allowed to purchase an additional policy
     * although lines get blurred if a policy expires and then user wants to re-purchase
     */
    public function canRepurchase()
    {
        // active/unpaid or polices in the renewal flow should not be able to be repurchased
        if (in_array($this->getStatus(), [
            self::STATUS_ACTIVE,
            self::STATUS_PENDING_RENEWAL,
            self::STATUS_RENEWAL,
            self::STATUS_UNPAID,
            self::STATUS_PICSURE_REQUIRED
        ])) {
            return false;
        }

        // we could check the status on the next policy as well, but if next status is pending renewal
        // then current status *should* always be active/unpaid
        // TODO: what about a pending renewal that is then cancelled?

        // In case user was disallowed after pending renewal was created
        if (!$this->getUser()->canRepurchasePolicy($this)) {
            return false;
        }

        return true;
    }

    public function isRepurchase()
    {
        if ($this->getStatus()) {
            return false;
        }

        foreach ($this->getUser()->getPolicies() as $policy) {
            // Find any policies that match imei
            if ($policy->getId() != $this->getId() && $this->isSameInsurable($policy) && $policy->getStatus()) {
                return true;
            }
        }

        return false;
    }

    public function validateAllowedExcess()
    {
        if (!$this->getPremium() || !$this->getPremium()->getExcess()) {
            return;
        }

        if (!$this->getPolicyTerms()->isAllowedExcess($this->getPremium()->getExcess())) {
            throw new \Exception(sprintf(
                'Unable to set phone for policy %s as excess (%s) values do not match policy terms (%s).',
                $this->getId(),
                $this->getPremium()->getExcess(),
                count($this->getPolicyTerms()->getAllowedExcesses()) > 0 ?
                    $this->getPolicyTerms()->getAllowedExcesses()[0]->__toString() :
                    'missing'
            ));
        }
    }

    public function hasManualBacsPayment()
    {
        foreach ($this->getPayments() as $payment) {
            if ($payment instanceof BacsPayment && $payment->isManual()) {
                return true;
            }
        }

        return false;
    }

    public function hasBacsPaymentInProgress()
    {
        foreach ($this->getPayments() as $payment) {
            if ($payment instanceof BacsPayment && $payment->inProgress()) {
                return true;
            }
        }

        return false;
    }

    public function canBacsPaymentBeMadeInTime(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!$this->getPolicyExpirationDate()) {
            return false;
        }

        // pending policies can make a bacs payment in time
        if (!$this->isPolicy()) {
            return true;
        }

        //print $this->getPolicyExpirationDate()->format(\DateTime::ATOM) . PHP_EOL;
        $expirationDate = $this->getCurrentOrPreviousBusinessDay($this->getPolicyExpirationDate(), $date);
        //print $expirationDate->format(\DateTime::ATOM) . PHP_EOL;
        // 2 additional days: 1 day to account for it possibly being after the bacs submission time for the day
        // and 1 day to account for policy expiration occurring before the bacs file for the day being uploaded
        $expirationDate = static::subBusinessDays($expirationDate, BacsPayment::DAYS_REVERSE + 2);

        //print $date->format(\DateTime::ATOM) . PHP_EOL;
        //print $expirationDate->format(\DateTime::ATOM) . PHP_EOL;

        return $this->startOfDay($expirationDate) >= $this->startOfDay($date);
    }

    public function isFacebookUserInvited($facebookId)
    {
        $isInvited = false;
        foreach ($this->getSentInvitations() as $invitation) {
            if ($invitation->getChannel() == 'facebook' &&
                $facebookId == $invitation->getFacebookId()) {
                $isInvited = true;
                break;
            }
        }
        foreach ($this->getUser()->getUnprocessedReceivedInvitations() as $invitation) {
            if ($invitation->getInviter()->getFacebookId() == $facebookId) {
                $isInvited = true;
                break;
            }
        }
        return $isInvited;
    }

    public function isFacebookUserConnected($facebookId)
    {
        $isConnected = false;
        foreach ($this->getConnections() as $connection) {
            if ($connection->getLinkedUser()->getFacebookId() == $facebookId) {
                $isConnected = true;
                break;
            }
        }
        return $isConnected;
    }

    /**
     * Tells you whether this policy should be used for attribution.
     * @return boolean true if it should be used for the user's attribution, and false otherwise.
     */
    public function useForAttribution()
    {
        if (!$this->isPolicy()) {
            return false;
        }
        $attributionPolicy = $this->getUser()->getAttributionPolicy();
        if (!$attributionPolicy) {
            return false;
        }
        return $this->getId() == $attributionPolicy->getId();
    }

    protected function toApiArray()
    {
        if ($this->isPolicy() && !$this->getPolicyTerms() && in_array($this->getStatus(), [
            self::STATUS_ACTIVE,
            self::STATUS_CANCELLED,
            self::STATUS_PICSURE_REQUIRED,
            self::STATUS_UNPAID,
            self::STATUS_EXPIRED,
            self::STATUS_EXPIRED_CLAIMABLE,
            self::STATUS_EXPIRED_WAIT_CLAIM,
            self::STATUS_RENEWAL,
        ])) {
            throw new \Exception(sprintf('Policy %s is missing terms', $this->getId()));
        }
        $cardDetails = CheckoutPaymentMethod::emptyApiArray();
        if ($this->getCheckoutPaymentMethod()) {
            $cardDetails = $this->getCheckoutPaymentMethod()->toApiArray();
        }

        // Figure out the right premiums to report even when none are actually set yet.
        $monthlyPremium = null;
        $yearlyPremium = null;
        $premium = $this->getPremium();
        if ($premium) {
            $monthlyPremium = $premium->getMonthlyPremiumPrice();
            $yearlyPremium = $premium->getYearlyPremiumPrice();
        } elseif ($this instanceof PhonePolicy) {
            $phone = $this->getPhone();
            if ($phone) {
                $monthlyPhonePrice = $phone->getCurrentMonthlyPhonePrice();
                $yearlyPhonePrice = $phone->getCurrentYearlyPhonePrice();
                $monthlyPremium = $monthlyPhonePrice ? $monthlyPhonePrice->getMonthlyPremiumPrice() : null;
                $yearlyPremium = $yearlyPhonePrice ? $yearlyPhonePrice->getYearlyPremiumPrice() : null;
            }
        }

        $type = 'phone';

        if ($this instanceof SalvaPhonePolicy) {
            $type = self::TYPE_SALVA_PHONE;
        } elseif ($this instanceof HelvetiaPhonePolicy) {
            $type = self::TYPE_HELVETIA_PHONE;
        }

        $data = [
            'id' => $this->getId(),
            'status' => $this->getApiStatus(),
            'type' => $type,
            'start_date' => $this->getStart() ? $this->getStart()->format(\DateTime::ATOM) : null,
            'end_date' => $this->getEnd() ? $this->getEnd()->format(\DateTime::ATOM) : null,
            'policy_number' => $this->getPolicyNumber(),
            'monthly_premium' => $monthlyPremium,
            'yearly_premium' => $yearlyPremium,
            'adjusted_monthly_premium' => $premium ? $premium->getAdjustedStandardMonthlyPremiumPrice() : null,
            'adjusted_yearly_premium' => $premium ? $premium->getAdjustedYearlyPremiumPrice() : null,
            'policy_terms_id' => $this->getPolicyTerms() ? $this->getPolicyTerms()->getId() : null,
            'pot' => [
                'connections' => count($this->getConnections()),
                'max_connections' => $this->getMaxConnections(),
                'value' => $this->getPotValue(),
                'max_value' => $this->getMaxPot(),
                'historical_max_value' => $this->getHistoricalMaxPotValue(),
                'connection_values' => $this->getConnectionValues(),
            ],
            'sent_invitations' => $this->eachApiArray($this->getSentInvitations()),
            'promo_code' => $this->getPromoCode(),
            'has_claim' => $this->hasMonetaryClaimed(),
            'has_network_claim' => $this->hasNetworkClaim(true),
            'claim_dates' => $this->eachApiMethod($this->getMonetaryClaimed(), 'getClosedDate'),
            'premium' => $this->getPremiumInstallmentPrice(),
            'premium_plan' => $this->getPremiumPlan(),
            'scodes' => $this->eachApiArray($this->getActiveSCodes()),
            'premium_payments' => $this->getPremiumPayments(),
            'premium_gwp' => $this->getPremiumGwpInstallmentPrice(),
            'facebook_filters' => $this->eachApiMethod($this->getSentInvitations(), 'getInviteeFacebookId', false),
            'previous_policy_id' => $this->hasPreviousPolicy() ? $this->getPreviousPolicy()->getId() : null,
            'next_policy_id' => $this->hasNextPolicy() ? $this->getNextPolicy()->getId() : null,
            'billing_day' => $this->getBillingDay(),
            'cashback_status' => $this->getCashback() ? $this->getCashback()->getStatus() : null,
            'has_payment_method' => $this->hasValidPaymentMethod(),
            'payment_details' => $this->getPaymentMethod() ?
                $this->getPaymentMethod()->__toString() :
                'Please update your payment details',
            'payment_method' => $this->getPaymentMethod() ?
                $this->getPaymentMethod()->getType() :
                null,
            'bank_account' => $this->getBacsBankAccount() ?
                $this->getBacsBankAccount()->toApiArray() :
                null,
            'has_time_bacs_payment' => $this->canBacsPaymentBeMadeInTime(),
            'card_details' => $cardDetails,
            'premium_owed' => $this->getStatus() == self::STATUS_UNPAID ?
                $this->getOutstandingPremiumToDateWithReschedules(new \DateTime()) : 0
        ];

        if ($this->getStatus() == self::STATUS_RENEWAL) {
            $data['connections'] = $this->eachApiArray($this->getRenewalConnections(), $this->getNetworkClaims());
        } else {
            $data['connections'] = $this->eachApiArray($this->getNonRewardConnections(), $this->getNetworkClaims());
        }

        return $data;
    }

    public static function sumYearlyPremiumPrice($policies, $activeUnpaidOnly = false)
    {
        $total = 0;
        foreach ($policies as $policy) {
            if ($policy->isValidPolicy()) {
                $includePolicy = true;
                if ($activeUnpaidOnly && !$policy->isActive()) {
                    $includePolicy = false;
                }
                if ($includePolicy) {
                    $total += $policy->getYearlyPremiumPrice();
                }
            }
        }
        return $total;
    }
}
