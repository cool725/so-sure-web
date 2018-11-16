<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use AppBundle\Exception\ValidationException;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Coordinates
{
    /**
     * @MongoDB\Field(type="collection")
     * @Gedmo\Versioned
     */
    protected $coordinates; // [longitude, latitude]
 
     /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     */
    protected $created;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    public $type = "Point";

    public function __construct()
    {
        $this->created = \DateTime::createFromFormat('U', time());
    }

    public function setCoordinates(float $longitude, float $latitude)
    {
        if ($longitude >= -180 && $longitude <= 180 && $latitude >= -90 && $latitude <= 90) {
            $this->coordinates = [$longitude, $latitude];
        } else {
            throw new ValidationException("Invalid coordinates");
        }
    }

    public function getCoordinates()
    {
        return $this->coordinates;
    }

    public function getCreated()
    {
        return $this->created;
    }

    public function setCreated($created)
    {
        $this->created = $created;
    }
}
