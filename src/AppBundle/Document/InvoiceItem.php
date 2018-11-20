<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class InvoiceItem
{
    use CurrencyTrait;

    /**
     * @MongoDB\Id
     */
    protected $id;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="250")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $description;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $unitPrice;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $quantity;

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $total;

    public function __construct($unitPrice = null, $quantity = 1)
    {
        $this->setUnitPrice($unitPrice);
        $this->setQuantity($quantity);
    }

    public function getId()
    {
        return $this->id;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function setDescription($description)
    {
        $this->description = $description;
    }

    public function getUnitPrice()
    {
        return $this->unitPrice;
    }

    public function setUnitPrice($unitPrice)
    {
        $this->unitPrice = $unitPrice;
        $this->calculate();
    }

    public function getQuantity()
    {
        return $this->quantity;
    }

    public function setQuantity($quantity)
    {
        $this->quantity = $quantity;
        $this->calculate();
    }

    public function getTotal()
    {
        return $this->total;
    }

    public function setTotal($total)
    {
        $this->total = $total;
    }

    public function calculate()
    {
        if ($this->getQuantity() && $this->getUnitPrice()) {
            $this->setTotal($this->toTwoDp($this->getQuantity() * $this->getUnitPrice()));
        }
    }
}
