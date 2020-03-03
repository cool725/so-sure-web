<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\ChargeRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Charge
{
    use CurrencyTrait;

    // sync with $type choices
    const TYPE_ADDRESS = 'address';
    const TYPE_GSMA = 'gsma';
    const TYPE_MAKEMODEL = 'makemodel';
    const TYPE_CLAIMSCHECK = 'claimscheck';
    const TYPE_CLAIMSDAMAGE = 'claimsdamage';
    const TYPE_BANK_ACCOUNT = 'bank-account';
    const TYPE_AFFILIATE = 'affiliate';
    const TYPE_SMS_INVITATION = 'sms-invitation';
    const TYPE_SMS_VERIFICATION = 'sms-verification';
    const TYPE_SMS_DOWNLOAD = 'sms-download';
    const TYPE_SMS_PAYMENT = 'sms-payment';
    const TYPE_SMS_GENERAL = 'sms-general';

    public static $prices = [
        self::TYPE_ADDRESS => 0.037, // ex vat
        self::TYPE_GSMA => 0.02, // ex vat
        self::TYPE_MAKEMODEL => 0.05, // ex vat
        self::TYPE_CLAIMSCHECK => 0.9, // ex vat
        self::TYPE_CLAIMSDAMAGE => 0.02, // ex vat
        self::TYPE_BANK_ACCOUNT => 0.037, // ex vat
        self::TYPE_SMS_INVITATION => 0.03, // ex vat
        self::TYPE_SMS_VERIFICATION => 0.03, // ex vat
        self::TYPE_SMS_DOWNLOAD => 0.03, // ex vat
        self::TYPE_SMS_PAYMENT => 0.03, // ex vat
        self::TYPE_SMS_GENERAL => 0.03, // ex vat
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $createdDate;

    /**
     * @Assert\Choice({"address", "gsma", "makemodel", "claimscheck", "claimsdamage", "bank-account", "affiliate",
     *    "sms-invitation", "sms-verification", "sms-download", "sms-payment", "sms-general"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $type;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\User", inversedBy="charges")
     * @Gedmo\Versioned
     */
    protected $user;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\AffiliateCompany")
     * @Gedmo\Versioned
     */
    protected $affiliate;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Participation")
     * @Gedmo\Versioned
     */
    protected $participation;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Policy")
     * @Gedmo\Versioned
     */
    protected $policy;

    /**
     * @MongoDB\ReferenceOne(targetDocument="AppBundle\Document\Claim")
     * @Gedmo\Versioned
     */
    protected $claim;

    /**
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     */
    public $handler;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $amount = 0;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $details;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Invoice")
     * @Gedmo\Versioned
     */
    protected $invoice;

    public function __construct()
    {
        $this->createdDate = \DateTime::createFromFormat('U', time());
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCreatedDate()
    {
        return $this->createdDate;
    }

    public function setCreatedDate($createdDate)
    {
        $this->createdDate = $createdDate;
    }

    public function getUser()
    {
        return $this->user;
    }

    public function setUser($user)
    {
        $this->user = $user;
    }

    public function getAffiliate()
    {
        return $this->affiliate;
    }

    public function setAffiliate($affiliate)
    {
        $this->affiliate = $affiliate;
    }

    public function getParticipation()
    {
        return $this->participation;
    }

    public function setParticipation(Participation $participation)
    {
        $this->participation = $participation;
    }

    public function getPolicy()
    {
        return $this->policy;
    }

    public function setPolicy($policy)
    {
        $this->policy = $policy;
    }

    public function getClaim()
    {
        return $this->claim;
    }

    public function setClaim($claim)
    {
        $this->claim = $claim;
    }

    public function getHandler()
    {
        return $this->handler;
    }

    public function setHandler($handler)
    {
        $this->handler = $handler;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setType($type)
    {
        $this->type = $type;
        if (isset(self::$prices[$type])) {
            $this->setAmount(self::$prices[$type]);
        }
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;
    }

    public function getAmountWithVat()
    {
        return $this->toTwoDp($this->getAmount() * (1 + $this->getCurrentVatRate()));
    }

    public function getDetails()
    {
        return $this->details;
    }

    public function setDetails($details)
    {
        $this->details = $details;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function setInvoice($invoice)
    {
        $this->invoice = $invoice;
    }

    public function __toString()
    {
        if ($this->getClaim()) {
            return sprintf(
                '%s for %s on %s',
                ucfirst($this->getType()),
                $this->getClaim()->getNumber(),
                $this->getCreatedDate()->format('d M Y')
            );
        } else {
            return sprintf(
                '%s on %s',
                ucfirst($this->getType()),
                $this->getCreatedDate()->format('d M Y')
            );
        }
    }

    /**
     * Takes a list of charges and tells you the total cost of them
     * @param array $charges is the list of charges.
     * @return float total cost.
     */
    public static function sumCost($charges)
    {
        $sum = 0;
        foreach ($charges as $charge) {
            $sum += $charge->getAmount();
        }
        return $sum;
    }
}
