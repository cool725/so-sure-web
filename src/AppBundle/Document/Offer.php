<?php

namespace AppBundle\Document;

use AppBundle\Classes\Salva;
use AppBundle\Document\Premium;
use AppBundle\Interfaces\EqualsInterface;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Represents an offer to a user for a lowered premium on a given model of phone.
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Offer
{
    /**
     * Time at which the offer was created. Does not need to be used for any functionality but is kept for reference.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $start;
    
    /**
     * Time at which the offer will stop working.
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $end;

    /**
     * The offered premium.
     * @MongoDB\EmbedOne(targetDocument="AppBundle\Document\Premium")
     * @Gedmo\Versioned
     * @var Premium
     */
    protected $premium;

    /**
     * The phone model for which this offer is made.
     * @MongoDB\ReferenceOne(targetDocument="Phone")
     * @Gedmo\Versioned
     * @var Phone
     */
    protected $phone;

    public function getStart()
    {
        return $this->start;
    }

    public function setStart($start)
    {
        $this->start = $start;
    }

    public function getEnd()
    {
        return $this->end;
    }

    public function setEnd($end)
    {
        $this->end = $end;
    }

    public function getPremium()
    {
        return $this->premium;
    }

    public function setPremium($premium)
    {
        $this->premium = $premium;
    }

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone($phone)
    {
        $this->phone = $phone;
    }
};
