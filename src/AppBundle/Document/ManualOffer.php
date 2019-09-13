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
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class ManualOffer
{
    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * Time at which the manual offer was created.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $created;

    /**
     * Time at which the offer should stop applying to the user.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $expires;

    /**
     * @MongoDB\ReferenceOne(targetDocument="Offer")
     * @Gedmo\Versioned
     * @var Offer
     */
    protected $offer;

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
     * Tells you when the manual offer expires.
     * @return \DateTime the date of that point.
     */
    public function getExpires()
    {
        return $this->expires;
    }

    /**
     * Sets when the manual offer expires.
     * @param \DateTime $expires is when it should expire.
     */
    public function setExpires($expires)
    {
        $this->expires = $expires;
    }

    /**
     * Gives the offer that this manual offer is linking to the user.
     * @return Offer the offer.
     */
    public function getOffer()
    {
        return $this->offer;
    }

    /**
     * Sets the offer that this manual offer is linking to the user.
     * @param Offer $offer is the offer to link.
     */
    public function setOffer($offer)
    {
        $this->offer = $offer;
    }
}
