<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\CompanyRepository")
 * @MongoDB\InheritanceType("SINGLE_COLLECTION")
 * @MongoDB\DiscriminatorField("type")
 * make sure to update getType() if adding
 * @MongoDB\DiscriminatorMap({
 *      "customer"="CustomerCompany",
 *      "affiliate"="AffiliateCompany",
 * })
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
abstract class Company
{
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
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="150")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $name;

    /**
     * @MongoDB\EmbedOne(targetDocument="Address")
     * @Gedmo\Versioned
     */
    protected $address;

    /**
     * @MongoDB\ReferenceMany(targetDocument="User", mappedBy="company")
     */
    protected $users;

    /**
     * @MongoDB\Field(type="hash")
     * @Gedmo\Versioned
     */
    protected $sanctionsChecks = array();

    /**
     * @MongoDB\EmbedMany(
     *  targetDocument="AppBundle\Document\SanctionsMatch"
     * )
     */
    protected $sanctionsMatches = array();

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
    }

    public function getId()
    {
        return $this->id;
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getAddress()
    {
        return $this->address;
    }

    public function setAddress(Address $address)
    {
        $this->address = $address;
    }

    public function getUsers()
    {
        return $this->users;
    }

    public function addUser(User $user)
    {
        $user->setCompany($this);
        $this->users[] = $user;
    }

    public function addSanctionsCheck(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $timestamp = $date->format('U');
        $this->sanctionsChecks[] = $timestamp;
    }

    public function getSanctionsChecks()
    {
        return $this->sanctionsChecks;
    }

    public function addSanctionsMatch(SanctionsMatch $sanctionsMatch)
    {
        // only ever allow one match per sanctions record
        foreach ($this->sanctionsMatches as $match) {
            if ($match->getSanctions()->getId() == $sanctionsMatch->getSanctions()->getId()) {
                return;
            }
        }

        $this->sanctionsMatches[] = $sanctionsMatch;
    }

    public function getSanctionsMatches()
    {
        return $this->sanctionsMatches;
    }
}
