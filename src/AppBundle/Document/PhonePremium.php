<?php

namespace AppBundle\Document;

use AppBundle\Document\Excess\PhoneExcess;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class PhonePremium extends Premium
{
    use CurrencyTrait;

    /**
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Excess\PhoneExcess")
     * @Gedmo\Versioned
     * @var PhoneExcess|null
     */
    protected $picSureExcess;

    public function setPicSureExcess(PhoneExcess $phoneExcess = null)
    {
        $this->picSureExcess = $phoneExcess;
    }

    public function getPicSureExcess()
    {
        return $this->picSureExcess;
    }
}
