<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/** @MongoDB\EmbeddedDocument */
class Coordinates
{
    /** @MongoDB\Field(type="collection") */
    public $coordinates;
 
    /** @MongoDB\Field(type="string") */
    public $type = "Point";
}
