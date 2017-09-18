<?php

namespace CensusBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use GeoJson\Geometry\Point;

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

    public function asPoint()
    {
        return new Point($this->coordinates);
    }
}
