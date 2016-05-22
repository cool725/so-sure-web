<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class Sequence
{
    /** @MongoDB\Id(type="string", strategy="NONE") */
    protected $name;

    /** @MongoDB\Field(type="int") */
    protected $seq;
}
