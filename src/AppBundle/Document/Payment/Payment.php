<?php

namespace AppBundle\Document\Payment;

use AppBundle\Classes\Salva;
use AppBundle\Classes\SoSure;
use AppBundle\Document\DateTrait;
use AppBundle\Document\IdentityLog;
use AppBundle\Document\Policy;
use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\User;
use AppBundle\Document\ScheduledPayment;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PaymentRepository")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * make sure to update getType() if adding
 * @MongoDB\DiscriminatorMap({
 *      "judo"="JudoPayment",
 *      "sosure"="SoSurePayment",
 *      "checkout"="CheckoutPayment",
 *      "bacs"="BacsPayment",
 *      "bacsIndemnity"="BacsIndemnityPayment",
 *      "chargeback"="ChargebackPayment",
 *      "potReward"="PotRewardPayment",
 *      "policyDiscount"="PolicyDiscountPayment",
 *      "policyDiscountRefund"="PolicyDiscountRefundPayment",
 *      "sosurePotReward"="SoSurePotRewardPayment",
 *      "debtCollection"="DebtCollectionPayment"
 * })
 * @MongoDB\Index(keys={"type"="asc"}, sparse="true")
 * @MongoDB\Index(keys={"type"="asc","date"="asc"}, sparse="true")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
abstract class Payment
{
    use CurrencyTrait;
    use DateTrait;

    // make sure to add to source below for new entries
    const SOURCE_TOKEN = 'token';
    const SOURCE_WEB = 'web';
    const SOURCE_WEB_API = 'web-api';
    const SOURCE_MOBILE = 'mobile';
    const SOURCE_APPLE_PAY = 'apple-pay';
    const SOURCE_ANDROID_PAY = 'android-pay';

    // DEPRECIATED
    const SOURCE_SOSURE = 'sosure';

    // DEPRECIATED
    const SOURCE_BACS = 'bacs';

    const SOURCE_SYSTEM = 'system';
    const SOURCE_ADMIN = 'admin';

    /**
     * @MongoDB\Id
     */
    protected $id;

    public function getType()
    {
        if ($this instanceof JudoPayment) {
            return 'judo';
        } elseif ($this instanceof SoSurePayment) {
            return 'sosure';
        } elseif ($this instanceof BacsPayment) {
            return 'bacs';
        } elseif ($this instanceof BacsIndemnityPayment) {
            return 'bacsIndemnity';
        } elseif ($this instanceof ChargebackPayment) {
            return 'chargeback';
        } elseif ($this instanceof PotRewardPayment) {
            return 'potReward';
        } elseif ($this instanceof SoSurePotRewardPayment) {
            return 'sosurePotReward';
        } elseif ($this instanceof PolicyDiscountPayment) {
            return 'policyDiscount';
        } elseif ($this instanceof CheckoutPayment) {
            return 'checkout';
        } else {
            return null;
        }
    }

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
     * @MongoDB\Index(unique=false)
     */
    protected $date;

    /**
     * @Assert\Range(min=-200,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $amount;

    /**
     * @Assert\Range(min=-200,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $gwp;

    /**
     * @Assert\Range(min=-200,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $ipt;

    /**
     * @Assert\Range(min=-200,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $totalCommission;

    /**
     * @Assert\Range(min=-200,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $coverholderCommission;

    /**
     * @Assert\Range(min=-200,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $brokerCommission;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(sparse=true)
     * @Gedmo\Versioned
     */
    protected $reference;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="200")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $details;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="payments")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $policy;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true, sparse=true)
     * @Gedmo\Versioned
     */
    protected $receipt;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User", inversedBy="payments")
     * @Gedmo\Versioned
     */
    protected $user;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\ScheduledPayment", inversedBy="payment")
     * @var ScheduledPayment|null
     */
    protected $scheduledPayment;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="200")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(sparse=true)
     * @Gedmo\Versioned
     */
    protected $notes;

    /**
     * sosure & bacs deprecated sources
     * @Assert\Choice({
     *      "mobile",
     *      "web",
     *      "web-api",
     *      "token",
     *      "apple-pay",
     *      "android-pay",
     *      "sosure",
     *      "bacs",
     *      "system",
     *      "admin"
     * }, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $source;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $success;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $skipCommissionValidation;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\IdentityLog")
     * @Gedmo\Versioned
     * @var IdentityLog
     */
    protected $identityLog;

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
        $this->date = \DateTime::createFromFormat('U', time());
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        if ($this->id) {
            throw new \Exception('Can not reasssign id');
        }

        $this->id = $id;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate(\DateTime $date)
    {
        $this->date = $date;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function getSource()
    {
        return $this->source;
    }

    public function getSourceForClaims()
    {
        if ($this->getSource() == self::SOURCE_WEB) {
            return "Web";
        } elseif (in_array($this->getSource(), [
                self::SOURCE_MOBILE,
                self::SOURCE_ANDROID_PAY,
                self::SOURCE_APPLE_PAY
            ])) {
            return "Mobile";
        } elseif ($this instanceof BacsPayment) {
            return "Bacs";
        } else {
            if ($this->getSource()) {
                return sprintf("Unknown - please ask [Notes: %s]", $this->getSource());
            } else {
                return sprintf("Unknown - please ask");
            }
        }
    }

    public function setSource($source)
    {
        $this->source = $source;
    }

    public function setGwp($gwp)
    {
        $this->gwp = $gwp;
    }

    public function getGwp()
    {
        return $this->gwp;
    }

    public function setIpt($ipt)
    {
        $this->ipt = $ipt;
    }

    public function getIpt()
    {
        return $this->ipt;
    }

    public function getDetails()
    {
        return $this->details;
    }

    public function setDetails($details)
    {
        $this->details = $details;
    }

    public function setNotes($notes)
    {
        $this->notes = $notes;
    }

    public function getNotes()
    {
        return $this->notes;
    }

    public function setSuccess($success)
    {
        if ($this->hasSuccess() && $this->success != $success) {
            throw new \Exception(sprintf(
                'Attempting to overwrite success for payment %s to %s',
                $this->getId(),
                $success
            ));
        }

        $this->success = $success;
    }

    public function hasSuccess()
    {
        return $this->success !== null;
    }

    abstract public function isSuccess();
    abstract public function isUserPayment();

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

    public function isFee()
    {
        return false;
    }

    public function isStandardPayment()
    {
        return true;
    }

    public function isDiscount()
    {
        return false;
    }

    public function toString()
    {
        return $this->__toString();
    }

    public function getSkipCommissionValidation()
    {
        return $this->skipCommissionValidation;
    }

    public function setSkipCommissionValidation($skipCommissionValidation)
    {
        $this->skipCommissionValidation = $skipCommissionValidation;
    }

    public function __toString()
    {
        return sprintf(
            '£%0.2f on %s (%s)',
            $this->getAmount(),
            $this->getDate()->format('d/m/Y H:i:s'),
            $this->getType()
        );
    }

    public function calculateSplit()
    {
        $gwp = $this->getAmount() / (1 + $this->getPolicy()->getPremium()->getIptRate());
        $ipt = $this->getAmount() - $gwp;

        // We do not want to set to 2 dp here as we need to total the gwp across all months
        // and compare to yearly figure.  We would be off by several cent if rounded
        $this->setGwp($gwp);
        $this->setIpt($ipt);
    }

    public function setTotalCommission($totalCommission)
    {
        $this->totalCommission = $totalCommission;
        if ($this->areEqualToFourDp($totalCommission, Salva::YEARLY_TOTAL_COMMISSION)) {
            $this->coverholderCommission = Salva::YEARLY_COVERHOLDER_COMMISSION;
            $this->brokerCommission = Salva::YEARLY_BROKER_COMMISSION;
        } elseif ($this->areEqualToFourDp($totalCommission, Salva::MONTHLY_TOTAL_COMMISSION)) {
            $this->coverholderCommission = Salva::MONTHLY_COVERHOLDER_COMMISSION;
            $this->brokerCommission = Salva::MONTHLY_BROKER_COMMISSION;
        } elseif ($this->areEqualToFourDp($totalCommission, Salva::FINAL_MONTHLY_TOTAL_COMMISSION)) {
            $this->coverholderCommission = Salva::FINAL_MONTHLY_COVERHOLDER_COMMISSION;
            $this->brokerCommission = Salva::FINAL_MONTHLY_BROKER_COMMISSION;
        } else {
            // Partial refund
            $salva = new Salva();
            $split = $salva->getProrataSplit($totalCommission);
            $this->coverholderCommission = $split['coverholder'];
            $this->brokerCommission = $split['broker'];
        }
    }

    public function setRefundTotalCommission($totalCommission)
    {
        $this->setTotalCommission(abs($totalCommission));
        $this->totalCommission = 0 - $this->totalCommission;
        $this->coverholderCommission = 0 - $this->coverholderCommission;
        $this->brokerCommission = 0 - $this->brokerCommission;
    }

    /**
     * quick hack in order for chargeback form to work with setRefundTotalCommission
     */
    public function getRefundTotalCommission()
    {
        return $this->totalCommission;
    }

    public function getTotalCommission()
    {
        return $this->totalCommission;
    }

    public function getCoverholderCommission()
    {
        return $this->coverholderCommission;
    }

    public function getBrokerCommission()
    {
        return $this->brokerCommission;
    }

    public function setReference($reference)
    {
        $this->reference = $reference;
    }

    public function getReference()
    {
        return $this->reference;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    /**
     * @return Policy
     */
    public function getPolicy()
    {
        return $this->policy;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function getReceipt()
    {
        return $this->receipt;
    }

    public function setReceipt($receipt)
    {
        $this->receipt = $receipt;
    }

    /**
     * @return ScheduledPayment|null
     */
    public function getScheduledPayment()
    {
        return $this->scheduledPayment;
    }

    /**
     * @param ScheduledPayment|null $scheduledPayment
     *
     */
    public function setScheduledPayment(ScheduledPayment $scheduledPayment = null)
    {
        $this->scheduledPayment = $scheduledPayment;
    }

    /**
     * Sets the commission for this payment.
     * @param boolean $allowFraction is whether it will allow the commission to be a fraction of monthly commission.
     */
    public function setCommission($allowFraction = false)
    {
        if (!$this->getPolicy()) {
            throw new \Exception(sprintf(
                'Attempting to set commission for %f (payment %s) without a policy',
                $this->getAmount(),
                $this->getId()
            ));
        }

        $salva = new Salva();
        $premium = $this->getPolicy()->getPremium();

        // Only set broker fees if we know the amount
        if ($this->areEqualToFourDp($this->getAmount(), $this->getPolicy()->getPremium()->getYearlyPremiumPrice())) {
            $commission = $salva->sumBrokerFee(12, true);
            $this->setTotalCommission($commission);
        } elseif ($premium->isEvenlyDivisible($this->getAmount()) ||
            $premium->isEvenlyDivisible($this->getAmount(), true)) {
            // payment should already be credited at this point
            $includeFinal = $this->areEqualToTwoDp(0, $this->getPolicy()->getOutstandingPremium());

            $numPayments = $premium->getNumberOfMonthlyPayments($this->getAmount());
            $commission = $salva->sumBrokerFee($numPayments, $includeFinal);
            $this->setTotalCommission($commission);
        } elseif ($allowFraction &&
            abs($this->getAmount()) <= $this->getPolicy()->getPremium()->getMonthlyPremiumPrice()
        ) {
            $fraction = $this->getAmount() / $this->getPolicy()->getPremium()->getMonthlyPremiumPrice();
            $this->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION * $fraction);
        } else {
            throw new \Exception(sprintf(
                'Failed to set correct commission for %f (policy %s)',
                $this->getAmount(),
                $this->getPolicy()->getId()
            ));
        }
    }

    /**
     * Tells if a payment should be visible to users.
     * @return boolean true if the user should see it, otherwise false.
     */
    public function isVisibleUserPayment()
    {
        return false;
    }

    /**
     * Gives a public description of the payment to be viewed externally by users or something.
     * Just defaults to payment, and is meant to be extended by subclasses.
     * @return string containing the name.
     */
    public function getUserPaymentDisplay()
    {
        return $this->userPaymentName();
    }

    public function toApiArray()
    {
        return [
            'date' => $this->getDate() ? $this->getDate()->format(\DateTime::ATOM) : null,
            'amount' => $this->getAmount() ? $this->toTwoDp($this->getAmount()) : null,
            'type' => $this->getType() ? $this->getType() : null,
        ];
    }

    public static function sumPayments($payments, $requireValidPolicy, $class = null)
    {
        $data = [
            'total' => 0,
            'numPayments' => 0,
            'numReceived' => 0,
            'numRefunded' => 0,
            'received' => 0,
            'refunded' => 0,
            'numFees' => 0,
            'fees' => 0,
            'totalCommission' => 0,
            'totalCommissionPercent' => 0,
            'coverholderCommission' => 0,
            'coverholderCommissionPercent' => 0,
            'brokerCommission' => 0,
            'brokerCommissionPercent' => 0,
            'totalUnderwriter' => 0,
            'totalUnderwriterPercent' => 0,
            'avgPayment' => null,
        ];
        if (!$payments) {
            return $data;
        }
        foreach ($payments as $payment) {
            /** @var Payment $payment */
            // For prod, skip invalid policies
            if ($requireValidPolicy && (!$payment->getPolicy() || !$payment->getPolicy()->isValidPolicy())) {
                continue;
            }
            if ($class && !$payment instanceof $class) {
                continue;
            }

            $data['total'] += $payment->getAmount();
            if ($payment->isFee()) {
                $data['fees'] += $payment->getAmount();
                $data['numFees']++;
            } elseif ($payment->getAmount() >= 0) {
                $data['received'] += $payment->getAmount();
                $data['numReceived']++;
            } else {
                $data['refunded'] += $payment->getAmount();
                $data['numRefunded']++;
            }
            $data['totalCommission'] += $payment->getTotalCommission();
            $data['coverholderCommission'] += $payment->getCoverholderCommission();
            $data['brokerCommission'] += $payment->getBrokerCommission();
            $data['numPayments']++;
        }
        if ($data['numPayments'] > 0) {
            $data['avgPayment'] = $data['total'] / $data['numPayments'];
        }
        $data['totalUnderwriter'] = $data['total'] - $data['totalCommission'];
        if ($data['total'] != 0) {
            $data['totalCommissionPercent'] = 100 * $data['totalCommission'] / $data['total'];
            $data['coverholderCommissionPercent'] = 100 * $data['coverholderCommission'] / $data['total'];
            $data['brokerCommissionPercent'] = 100 * $data['brokerCommission'] / $data['total'];
            $data['totalUnderwriterPercent'] = 100 * $data['totalUnderwriter'] / $data['total'];
        }

        return $data;
    }

    /**
     * @param mixed              $payments
     * @param boolean            $requireValidPolicy
     * @param string|null        $class
     * @param \DateTimeZone|null $timezone
     * @param string|null        $dateMethod
     * @return array|null
     */
    public static function dailyPayments(
        $payments,
        $requireValidPolicy,
        $class = null,
        \DateTimeZone $timezone = null,
        $dateMethod = null
    ) {
        if (count($payments) == 0) {
            return null;
        }

        $month = null;
        $data = [];
        $months = [];
        foreach ($payments as $payment) {
            /** @var Payment $payment */
            // For prod, skip invalid policies
            if ($requireValidPolicy && (!$payment->getPolicy() || !$payment->getPolicy()->isValidPolicy())) {
                continue;
            }
            if ($class && !$payment instanceof $class) {
                continue;
            }

            /** @var \DateTime $date */
            $date = null;
            if ($dateMethod) {
                $date = call_user_func([$payment, $dateMethod]);
            } else {
                $date = $payment->getDate();
            }
            if (!$date) {
                //$date = $payment->getDate();
                continue;
            }

            if ($timezone) {
                $date = self::convertTimezone($date, $timezone);
            }

            $month = $date->format('m');

            $day = (int) $date->format('d');

            if (!isset($data[$month][$day])) {
                $data[$month][$day] = 0;
            }
            $data[$month][$day] += CurrencyTrait::staticToTwoDp($payment->getAmount());
            $months[$month] = count($data[$month]);
        }

        arsort($months);
        reset($months);

        $key = key($months);
        if (isset($data[$key])) {
            return $data[$key];
        } else {
            return null;
        }
    }

    /**
     * Gives the name that this payment should be called by to users when there is not an overriding circumstance.
     * @return string containing the name.
     */
    protected function userPaymentName()
    {
        return "Other";
    }
}
