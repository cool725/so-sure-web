<?php

namespace AppBundle\Document;

use AppBundle\Classes\Salva;
use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * @MongoDB\DiscriminatorMap({"judo"="JudoPayment","gocardless"="GocardlessPayment"})
 * @Gedmo\Loggable
 */
abstract class Payment
{
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
     * @Assert\Length(min="1", max="50")
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
     * @MongoDB\Index(unique=true, sparse=false)
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

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->date = new \DateTime();
        $this->scheduledPayments = new \Doctrine\Common\Collections\ArrayCollection();
    }

    abstract public function isSuccess();

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

    public function calculateSplit()
    {
        $this->setIpt($this->getPolicy()->getPremium()->getIptRate() * $this->getAmount());
        $this->setGwp($this->getAmount() - $this->getIpt());
    }

    public function setTotalCommission($totalCommission)
    {
        $this->totalCommission = $totalCommission;
        if ($totalCommission == Salva::YEARLY_TOTAL_COMMISSION) {
            $this->coverholderCommission = Salva::YEARLY_COVERHOLDER_COMMISSION;
            $this->brokerCommission = Salva::YEARLY_BROKER_COMMISSION;
        } elseif ($totalCommission == Salva::MONTHLY_TOTAL_COMMISSION) {
            $this->coverholderCommission = Salva::MONTHLY_COVERHOLDER_COMMISSION;
            $this->brokerCommission = Salva::MONTHLY_BROKER_COMMISSION;
        } elseif ($totalCommission == Salva::FINAL_MONTHLY_TOTAL_COMMISSION) {
            $this->coverholderCommission = Salva::FINAL_MONTHLY_COVERHOLDER_COMMISSION;
            $this->brokerCommission = Salva::FINAL_MONTHLY_BROKER_COMMISSION;
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

    public static function sumPayments($payments, $requireValidPolicy)
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

            $day = $payment->getDate()->format('d');
            if (!isset($data[$day])) {
                $data[$day] = 0;
            }
            $data[$day] += $payment->getAmount();
        }

        return $data;
    }
}
