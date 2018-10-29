<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use VasilDakov\Postcode\Postcode;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable
 */
class SanctionsMatch
{
    /**
     * @MongoDB\ReferenceOne(targetDocument="Sanctions")
     * @Gedmo\Versioned
     */
    protected $sanctions;

    /**
     * @MongoDB\Field(type="integer")
     * @Gedmo\Versioned
     */
    protected $distance;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     */
    protected $manuallyVerified;

    public function __construct()
    {
        $this->date = \DateTime::createFromFormat('U', time());
    }

    public function setSanctions(Sanctions $sanctions)
    {
        $this->sanctions = $sanctions;
    }

    public function getSanctions()
    {
        return $this->sanctions;
    }

    public function setDistance($distance)
    {
        $this->distance = $distance;
    }

    public function getDistance()
    {
        return $this->distance;
    }

    public function setDate($date)
    {
        $this->date = $date;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setManuallyVerified($manuallyVerified)
    {
        $this->manuallyVerified = $manuallyVerified;
    }

    public function isManuallyVerified()
    {
        return $this->manuallyVerified;
    }
}
