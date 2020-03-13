<?php

namespace AppBundle\Document;

use AppBundle\Document\Opt\Opt;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Lead
{
    use PhoneTrait;

    // Add to source options below
    const SOURCE_TEXT_ME = 'text-me';
    const SOURCE_LAUNCH_USA = 'launch-usa';
    const SOURCE_BUY = 'buy';
    const SOURCE_SAVE_QUOTE = 'save-quote';
    const SOURCE_SEND_QUOTE = 'send-quote';
    const SOURCE_INVITE_NOT_READY = 'invite-not-ready';
    const SOURCE_PURCHASE_FLOW = 'purchase-flow';
    const SOURCE_CONTACT_US = 'contact-us';
    const SOURCE_QUOTE_EMAIL_HOME = 'quote-email-home';

    // Competition/Cold leads
    const SOURCE_COMPETITION = 'competition';

    // POS affiliate sources
    const SOURCE_DETAILS_POS_HELLOZ = 'helloz';

    // Lead Source is used in User & Policy
    const LEAD_SOURCE_INVITATION = 'invitation';
    const LEAD_SOURCE_SCODE = 'scode';
    const LEAD_SOURCE_AFFILIATE = 'affiliate';


    public static $leadSources = [
        self::LEAD_SOURCE_AFFILIATE,
        self::LEAD_SOURCE_INVITATION,
        self::LEAD_SOURCE_SCODE
    ];

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $created;

    /**
     * @AppAssert\Mobile()
     * @MongoDB\Field(type="string")
     */
    protected $mobileNumber;

    /**
     * @Assert\Email()
     * @MongoDB\Field(type="string")
     */
    protected $email;

    /**
     * @Assert\Email()
     * @MongoDB\Field(type="string")
     */
    protected $emailCanonical;

    /**
     * @var string
     * @AppAssert\FullName()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     */
    protected $name;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @Gedmo\Versioned
     * @var Phone
     */
    protected $phone;

    /**
     * @Assert\Choice({"text-me", "launch-usa", "buy", "save-quote",
     *                 "purchase-flow", "contact-us",
     *                 "invitation", "scode", "affiliate",
     *                 "invite-not-ready", "competition", "aggregator", "send-quote", "quote-email-home"}, strict=true)
     * @MongoDB\Field(type="string")
     */
    protected $source;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $sourceDetails;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $intercomId;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $intercomUserId;

    /**
     * @Assert\Length(min="0", max="20")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $shareCode;

    /**
    * @Assert\Ip()
    * @MongoDB\Field(type="string")
    * @Gedmo\Versioned
    */
    protected $ip;

    /**
     * @MongoDB\ReferenceMany(targetDocument="AppBundle\Document\Opt\Opt", mappedBy="lead", cascade={"persist"})
     */
    protected $opts = array();

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
    }

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone(Phone $phone)
    {
        $this->phone = $phone;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function getMobileNumber()
    {
        return $this->mobileNumber;
    }

    public function setMobileNumber($mobile)
    {
        $this->mobileNumber = $this->normalizeUkMobile($mobile);
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->emailCanonical = mb_convert_case($email, MB_CASE_LOWER);
        $this->email = $email;
    }

    public function getEmailCanonical()
    {
        return $this->emailCanonical;
    }

    public function setEmailCanonical($emailCanonical)
    {
        $this->emailCanonical = $emailCanonical;
    }

    public function hasEmail()
    {
        return mb_strlen(trim($this->getEmail())) > 0;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getSource()
    {
        return $this->source;
    }

    public function setSource($source)
    {
        $this->source = $source;
    }

    public function setSourceDetails($details)
    {
        $validator = new AppAssert\AlphanumericSpaceDotValidator();
        $this->sourceDetails = $validator->conform($details);
    }

    public function getSourceDetails()
    {
        return $this->sourceDetails;
    }

    public function getIntercomId()
    {
        return $this->intercomId;
    }

    public function setIntercomId($intercomId)
    {
        $this->intercomId = $intercomId;
    }

    public function getIntercomUserId()
    {
        return $this->intercomUserId;
    }

    public function setIntercomUserId($intercomUserId)
    {
        $this->intercomUserId = $intercomUserId;
    }

    public function getIntercomUserIdOrId()
    {
        return $this->getIntercomUserId() ?: $this->getId();
    }

    public function getShareCode()
    {
        return $this->shareCode;
    }

    public function setShareCode($shareCode)
    {
        $this->shareCode = $shareCode;
    }

    public function getIp()
    {
        return $this->ip;
    }

    public function setIp($ip)
    {
        $this->ip = $ip;
    }

    public function addOpt(Opt $opt)
    {
        $opt->setLead($this);
        $this->opts[] = $opt;
    }

    public function getOpts()
    {
        return $this->opts;
    }

    public function populateUser(User $user, $setMobileNumber = false)
    {
        if (!$user->getEmail()) {
            $user->setEmail($this->getEmail());
        }

        // Probably not necessary, but there may be some timing issues with the listeners and doens't really hurt
        if (!$user->getEmailCanonical()) {
            $user->setEmailCanonical($this->getEmailCanonical());
        }

        if (!$user->getLeadSource() && in_array($this->getSource(), self::$leadSources)) {
            $user->setLeadSource($this->getSource());
        }
        if (!$user->getLeadSourceDetails()) {
            $user->setLeadSourceDetails($this->getSourceDetails());
        }

        if (!$user->getIntercomId()) {
            $user->setIntercomId($this->getIntercomId());
        }

        // Use caution and check before setting as could cause duplicate mobile numbers, which could cause login issues
        if (!$user->getMobileNumber() && $setMobileNumber) {
            $user->setMobileNumber($this->getMobileNumber());
        }
    }
}
