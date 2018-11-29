<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 */
class LeadPortal
{
    use PhoneTrait;

    // Add to source options below
    const SOURCE_HELLO_Z = 'helloz';
    // const SOURCE_LAUNCH_USA = 'launch-usa';
    // const SOURCE_BUY = 'buy';
    // const SOURCE_SAVE_QUOTE = 'save-quote';
    // const SOURCE_PURCHASE_FLOW = 'purchase-flow';
    // const SOURCE_CONTACT_US = 'contact-us';

    // // Lead Source is used in User & Policy
    // const LEAD_SOURCE_INVITATION = 'invitation';
    // const LEAD_SOURCE_SCODE = 'scode';
    // const LEAD_SOURCE_AFFILIATE = 'affiliate';

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
     * @var boolean
     * @Assert\IsTrue(message="Who is submitting the form")
     */
    protected $submittedby;

    /**
     * @Assert\Email()
     * @MongoDB\Field(type="string")
     */
    protected $email;

    /**
     * @var string
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="1", max="50")
     * @Assert\NotBlank(message="This value is required.")
     */
    protected $firstName;

    /**
     * @var string
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @Assert\NotBlank(message="This value is required.")
     */
    protected $lastName;

    /**
     * @var string
     * @AppAssert\FullName()
     * @Assert\Length(min="1", max="100")
     * @Assert\NotBlank(message="This value is required.")
     */
    protected $name;

    /**
     * @var string
     * @AppAssert\Phone()
     * @Assert\Length(min="1", max="100")
     * @Assert\NotBlank(message="This value is required.")
     */
    protected $phone;

    /**
     * @Assert\Choice({"text-me", "launch-usa", "buy", "save-quote", "purchase-flow", "contact-us"}, strict=true)
     * @MongoDB\Field(type="string")
     */
    protected $source;

    /**
     * @var boolean
     * @Assert\IsTrue(message="You must agree to our terms")
     */
    protected $terms;


    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
    }

    public function getId()
    {
        return $this->id;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function setEmail($email)
    {
        $this->email = mb_strtolower($email);
    }

    public function setSubmittedBy($submittedby)
    {
        $this->submittedby = $submittedby;
    }

    public function getSubmittedBy()
    {
        return $this->submittedby;
    }

    public function getFirstName()
    {
        return $this->firstName;
    }

    public function setFirstName($firstName)
    {
        $this->firstName = $firstName;
    }

    public function getLastName()
    {
        return $this->lastName;
    }

    public function setLastName($lastName)
    {
        $this->lastName = $lastName;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = trim($name);
        $parts = explode(" ", trim($name));
        if (count($parts) == 2) {
            $this->setFirstName(ucfirst(mb_strtolower($parts[0])));
            $this->setLastName(ucfirst(mb_strtolower($parts[1])));
        }
    }

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone($phone)
    {
        $this->phone = $phone;
    }

    public function getSource()
    {
        return $this->source;
    }

    public function setSource($source)
    {
        $this->source = $source;
    }

    public function getTerms()
    {
        return $this->terms;
    }

    public function setTerms($terms)
    {
        $this->terms = $terms;
    }
    // public function populateUser(User $user)
    // {
        // $user->setEmail($this->getEmail());
        // $user->setCreated($this->getCreated());
        // Commenting out as could cause duplicate mobile numbers, which could cause login issues
        // $user->setMobileNumber($this->getMobileNumber());
    // }
}
