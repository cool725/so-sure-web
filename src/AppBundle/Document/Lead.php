<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
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

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Date()
     */
    protected $created;

    /**
     * @AppAssert\Mobile()
     * @MongoDB\Field(type="string")
     */
    protected $mobileNumber;

    /**
     * @Assert\Email(strict=false)
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
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
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
        $this->email = strtolower($email);
    }

    public function hasEmail()
    {
        return strlen(trim($this->getEmail())) > 0;
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
}
