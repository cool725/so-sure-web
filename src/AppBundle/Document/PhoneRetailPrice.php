<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Stores the retail price of a phone at a given time.
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class PhoneRetailPrice
{
    /**
     * Current retail price.
     * @Assert\Range(min=0,max=5000)
     * @MongoDB\Field(type="float")
     */
    protected $price;

    /**
     * Url linking to proof of current retail price being correct.
     * @Assert\Url(protocols = {"http", "https"})
     * @MongoDB\Field(type="string")
     */
    protected $url;

    /**
     * Date at which this retail price is current.
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

    /**
     * Gives you the proof url value for this price.
     * @return string the proof url.
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Sets the proof url for this retail price.
     * @param string $url is the url to set it to.
     */
    public function setUrl($url)
    {
        $this->url = $url;
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
