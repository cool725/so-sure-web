<?php

namespace AppBundle\Document;

use AppBundle\Classes\Salva;
use AppBundle\Document\PhonePrice;
use AppBundle\Interfaces\EqualsInterface;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Represents an offer to a user for a lowered premium on a given model of phone.
 * @MongoDB\Document(repositoryClass="AppBundle\Repository\OfferRepository")
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Offer
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * Time at which the offer was created. Does not need to be used for any functionality but is kept for reference.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * The offered price.
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Price")
     * @Gedmo\Versioned
     * @var Price
     */
    protected $price;

    /**
     * The phone model for which this offer is made.
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @Gedmo\Versioned
     * @var Phone
     */
    protected $phone;

    /**
     * The name of this offer. Not intended to be seen by users, just so admins can keep track of multiple offers on
     * the same phone and such.
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="100")
     * @MongoDB\Field(type="string")
     */
    protected $name;

    /**
     * Whether the offer is currently enabled.
     * @MongoDB\Field(type="boolean")
     */
    protected $active;

    /**
     * Contains all users that this offer is offered to.
     * @MongoDB\ReferenceOne(targetDocument="User")
     * @Gedmo\Versioned
     * @var User
     */
    protected $users = [];

    /**
     * Contains all policies that are using the price from this offer.
     * @MongoDB\ReferenceOne(targetDocument="Policy")
     * @Gedmo\Versioned
     * @var Policy
     */
    protected $policies = [];

    /**
     * Gives the time at which the offer was created.
     * @return \DateTime of creation.
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Sets the time at which this offer was created.
     * @param \DateTime $created is the time at which it was created.
     */
    public function setCreated($created)
    {
        $this->created = $created;
    }

    /**
     * Gives the price that this offer pertains to.
     * @return PhonePrice the price.
     */
    public function getPrice()
    {
        return $this->price;
    }

    /**
     * Sets the price for this offer.
     * @param PhonePrice $price the price.
     */
    public function setPrice($price)
    {
        $this->price = $price;
    }

    /**
     * Gives the phone that this offer is about.
     * @return Phone the phone.
     */
    public function getPhone()
    {
        return $this->phone;
    }

    /**
     * Sets what phone model this offer is about.
     * @param Phone $phone is the phone that the offer is about.
     */
    public function setPhone($phone)
    {
        $this->phone = $phone;
    }

    /**
     * Gives you the name of this offer.
     * @return string the name.
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Sets the name of the offer.
     * @param string $name is the new name to give.
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Tells you whether the offer is active.
     * @return boolean whether it is active.
     */
    public function getActive()
    {
        return $this->active;
    }

    /**
     * Sets the offer's activity status.
     * @param boolean $active is the value to set it to.
     */
    public function setActive($active)
    {
        $this->active = $active;
    }
}
