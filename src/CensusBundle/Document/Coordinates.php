<?php

namespace CensusBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use GeoJson\Geometry\Point;
use AppBundle\Exception\ValidationException;

/**
 * @MongoDB\EmbeddedDocument
 */
class Coordinates
{
    /**
     * @MongoDB\Field(type="collection")
     */
    public $coordinates;
 
    /**
     * @MongoDB\Field(type="string")
     */
    public $type = "Point";

    /**
     * @return Point|null
     */
    public function asPoint()
    {
        if ($this->coordinates) {
            return new Point($this->coordinates);
        } else {
            return null;
        }
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
}
