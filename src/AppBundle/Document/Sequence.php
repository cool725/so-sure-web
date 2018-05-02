<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 */
class Sequence
{
    /**
     * @AppAssert\Alphanumeric()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Id(type="string", strategy="NONE")
     */
    protected $name;

    /**
     * @MongoDB\Field(type="int")
     */
    protected $seq;

    public function getSeq()
    {
        return $this->seq;
    }

    /**
     * You should be using the SequenceService to increment
     *
     * @param string $seq
     */
    public function resetSeq($seq)
    {
        $this->seq = $seq;
    }
}
