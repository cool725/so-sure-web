<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable
 */
class Company
{
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
     * @MongoDB\ReferenceMany(targetDocument="Policy", mappedBy="company")
     */
    protected $policies;

    /**
     * @MongoDB\ReferenceMany(targetDocument="User", mappedBy="company")
     */
    protected $users;

    public function __construct()
    {
        $this->created = new \DateTime();
    }

    public function getId()
    {
        return $this->id;
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

    public function addPolicy(Policy $policy)
    {
        $policy->setCompany($this);
        $this->policies[] = $policy;
    }

    public function getPolicies()
    {
        return $this->policies;
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
}
