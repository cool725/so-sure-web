<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document
 */
class Sequence
{
    /** @MongoDB\Id(type="string", strategy="NONE", name="name") */
    protected $name;

    /** @MongoDB\Field(type="int", name="seq") */
    protected $seq;
}
