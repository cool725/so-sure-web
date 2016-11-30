<?php

namespace AppBundle\Document;

use AppBundle\Classes\Salva;
use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\PaymentRepository")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"judo"="JudoPayment","gocardless"="GocardlessPayment","sosure"="SoSurePayment"})
 * @Gedmo\Loggable
 */
abstract class Payment
{
    use CurrencyTrait;

    const SOURCE_TOKEN = 'token';
    const SOURCE_WEB = 'web';
    const SOURCE_WEB_API = 'web-api';
    const SOURCE_MOBILE = 'mobile';
    const SOURCE_APPLE_PAY = 'apple-pay';
    const SOURCE_ANDROID_PAY = 'android-pay';

    /**
     * @MongoDB\Id
     */
    protected $id;

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
     * @MongoDB\ReferenceOne(targetDocument="Policy", inversedBy="payments")
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
     * @MongoDB\ReferenceOne(targetDocument="User", inversedBy="payments")
     * @Gedmo\Versioned
     */
    protected $user;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\ScheduledPayment")
     */
    protected $scheduledPayments;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(sparse=true)
     * @Gedmo\Versioned
     */
    protected $notes;

    /**
     * @Assert\Choice({"mobile", "web", "web-api", "token"})
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

    public function setSource($source)
    {
        if (!in_array($source, ["mobile", "web", "web-api", "token"])) {
            throw new \Exception(sprintf('Unknown source %s', $source));
        }
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

    public function isSuccess()
    {
        return $this->success;
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
            } else {
                $data['refunded'] += $payment->getAmount();
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

    public static function dailyPayments($payments, $requireValidPolicy)
    {
        $month = null;
        $data = [];
        foreach ($payments as $payment) {
            // For prod, skip invalid policies
            if ($requireValidPolicy && !$payment->getPolicy()->isValidPolicy()) {
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
