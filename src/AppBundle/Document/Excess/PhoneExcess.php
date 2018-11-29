<?php

namespace AppBundle\Document\Excess;

use AppBundle\Document\CurrencyTrait;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class PhoneExcess extends Excess
{
    use CurrencyTrait;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $damage;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $warranty;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $extendedWarranty;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $loss;

    /**
     * @Assert\Range(min=0,max=200)
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $theft;

    public function getDamage()
    {
        return $this->damage;
    }

    public function setDamage($damage)
    {
        $this->damage = $damage;
    }

    public function getWarranty()
    {
        return $this->warranty;
    }

    public function setWarranty($warranty)
    {
        $this->warranty = $warranty;
    }

    public function getExtendedWarranty()
    {
        return $this->extendedWarranty;
    }

    public function setExtendedWarranty($extendedWarranty)
    {
        $this->extendedWarranty = $extendedWarranty;
    }

    public function getLoss()
    {
        return $this->loss;
    }

    public function setLoss($loss)
    {
        $this->loss = $loss;
    }

    public function getTheft()
    {
        return $this->theft;
    }

    public function setTheft($theft)
    {
        $this->theft = $theft;
    }

    public function __toString()
    {
        return sprintf(
            '%0.0f / %0.0f',
            $this->getDamage(),
            $this->getTheft()
        );
    }

    public function toApiArray()
    {
        return [
            'loss' => $this->toTwoDp($this->getLoss()),
            'theft' => $this->toTwoDp($this->getTheft()),
            'warranty' => $this->toTwoDp($this->getWarranty()),
            'extendedWarranty' => $this->toTwoDp($this->getExtendedWarranty()),
            'damage' => $this->toTwoDp($this->getDamage()),
            'detail' => $this->__toString(),
        ];
    }
}
