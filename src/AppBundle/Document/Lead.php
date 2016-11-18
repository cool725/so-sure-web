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

    const SOURCE_TEXT_ME = 'text-me';
    const SOURCE_LAUNCH_USA = 'launch-usa';
    const SOURCE_BUY = 'buy';

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
     * @Assert\Choice({"text-me", "launch-usa", "buy"})
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
        $this->email = $email;
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
