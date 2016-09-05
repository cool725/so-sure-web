<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

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
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="0", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    public $type = "Point";
}
