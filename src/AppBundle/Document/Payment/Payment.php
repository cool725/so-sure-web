<?php

namespace AppBundle\Document\Payment;

use AppBundle\Classes\Salva;
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
 *      "gocardless"="GocardlessPayment",
 *      "sosure"="SoSurePayment",
 *      "bacs"="BacsPayment",
 *      "potReward"="PotRewardPayment",
 *      "policyDiscount"="PolicyDiscountPayment"
 * })
 * @Gedmo\Loggable
 */
abstract class Payment
{
    use CurrencyTrait;

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
        } else {
            return null;
        }
    }

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
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy", inversedBy="payments")
     * @Gedmo\Versioned
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
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\ScheduledPayment")
     */
    protected $scheduledPayments;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
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

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->date = new \DateTime();
        $this->scheduledPayments = new \Doctrine\Common\Collections\ArrayCollection();
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
        $this->success = $success;
    }

    abstract public function isSuccess();

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

    public function getToken()
    {
        return $this->token;
    }

    public function setToken($token)
    {
        $this->token = $token;
    }

    public function getReceipt()
    {
        return $this->receipt;
    }

    public function setReceipt($receipt)
    {
        $this->receipt = $receipt;
    }

    public function getScheduledPayments()
    {
        return $this->scheduledPayments;
    }

    public function addScheduledPayment(ScheduledPayment $scheduledPayment)
    {
        $scheduledPayment->setPayment($this);
        $this->scheduledPayments[] = $scheduledPayment;
    }

    public function toApiArray()
    {
        return [
            'date' => $this->getDate() ? $this->getDate()->format(\DateTime::ATOM) : null,
            'amount' => $this->getAmount() ? $this->toTwoDp($this->getAmount()) : null,
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
        foreach ($payments as $payment) {
            // For prod, skip invalid policies
            if ($requireValidPolicy && !$payment->getPolicy()->isValidPolicy()) {
                continue;
            }
            if ($class && !$payment instanceof $class) {
                continue;
            }

            $data['total'] += $payment->getAmount();
            if ($payment->getAmount() >= 0) {
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

    public static function dailyPayments($payments, $requireValidPolicy, $class = null)
    {
        $month = null;
        $data = [];
        foreach ($payments as $payment) {
            // For prod, skip invalid policies
            if ($requireValidPolicy && !$payment->getPolicy()->isValidPolicy()) {
                continue;
            }
            if ($class && !$payment instanceof $class) {
                continue;
            }

            if (!$month) {
                $month = $payment->getDate()->format('m');
            } elseif ($month != $payment->getDate()->format('m')) {
                throw new \Exception('Payment list contains multiple months');
            }

            $day = (int) $payment->getDate()->format('d');

            if (!isset($data[$day])) {
                $data[$day] = 0;
            }
            $data[$day] += CurrencyTrait::staticToTwoDp($payment->getAmount());
        }

        return $data;
    }
}
