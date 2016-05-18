<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable
 */
class Coordinates
{
    /**
     * @MongoDB\Field(type="collection")
     * @Gedmo\Versioned
     */
    public $coordinates;
 
    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    public $type = "Point";
}
