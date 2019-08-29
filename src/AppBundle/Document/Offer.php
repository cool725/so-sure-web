<?php

namespace AppBundle\Document;

use AppBundle\Classes\Salva;
use AppBundle\Document\Price;
use AppBundle\Document\PhonePrice;
use AppBundle\Interfaces\EqualsInterface;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Represents an offer to a user for a lowered premium on a given model of phone.
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Offer
{
    /**
     * Time at which the offer was created. Does not need to be used for any functionality but is kept for reference.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $created;
    
    /**
     * The offered price which also stores the time period in which is it valid.
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\PhonePrice")
     * @Gedmo\Versioned
     * @var PhonePrice
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
     * @param PhonePrice the price.
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
};
