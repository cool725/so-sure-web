<?php

namespace AppBundle\Document;

use AppBundle\Document\DateTrait;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Stores the competitor price of a phone at a given time.
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class PhoneCompetitorPrice
{
    /**
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     */
    protected $competitor;

    /**
     * Current competitor price.
     * @Assert\Range(min=0,max=5000)
     * @MongoDB\Field(type="float")
     */
    protected $price;

    /**
     * Date at which this competitor price is current.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $date;

    /**
     * Gives you the price value.
     * @return float the price.
     */
    public function getPrice()
    {
        return $this->price;
    }

    /**
     * Set the price value.
     * @param float $price is the price to set it to.
     */
    public function setPrice($price)
    {
        $this->price = $price;
    }

    public function getCompetitor()
    {
        return $this->competitor;
    }

    public function setCompetitor($competitor)
    {
        $this->competitor = $competitor;
    }

    /**
     * Gives you the date from which this retail price is valid.
     * @return \DateTime the date.
     */
    public function getDate()
    {
        return $this->date;
    }

    /**
     * Sets the date from which this retail price is valid.
     * @param \DateTime $date is the date to set it to.
     */
    public function setDate(\DateTime $date)
    {
        $this->date = $date;
    }
}
