<?php

namespace AppBundle\Document;

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
    const SOURCE_PURCHASE_FLOW = 'purchase-flow';
    const SOURCE_CONTACT_US = 'contact-us';

    // Lead Source is used in User & Policy
    const LEAD_SOURCE_INVITATION = 'invitation';
    const LEAD_SOURCE_SCODE = 'scode';
    const LEAD_SOURCE_AFFILIATE = 'affiliate';

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
     * @Assert\Choice({"text-me", "launch-usa", "buy", "save-quote", "purchase-flow", "contact-us"}, strict=true)
     * @MongoDB\Field(type="string")
     */
    protected $source;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $intercomId;

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
        $this->email = mb_strtolower($email);
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

    public function getIntercomId()
    {
        return $this->intercomId;
    }

    public function setIntercomId($intercomId)
    {
        $this->intercomId = $intercomId;
    }

    public function populateUser(User $user)
    {
        $user->setEmail($this->getEmail());
        $user->setCreated($this->getCreated());
        $user->setIntercomId($this->getIntercomId());
        // Commenting out as could cause duplicate mobile numbers, which could cause login issues
        // $user->setMobileNumber($this->getMobileNumber());
    }
}
