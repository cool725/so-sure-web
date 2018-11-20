<?php

namespace AppBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;

/**
 * @MongoDB\Document
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
 */
class Invoice
{
    use CurrencyTrait;

    /**
     * @MongoDB\Id(strategy="auto")
     */
    protected $id;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     */
    protected $date;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $name;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="50")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     */
    protected $invoiceNumber;

    /**
     * @MongoDB\EmbedOne(targetDocument="Address")
     * @Gedmo\Versioned
     */
    protected $address;

    /**
     * @MongoDB\EmbedMany(targetDocument="InvoiceItem")
     */
    protected $invoiceItems = array();

    /**
     * @MongoDB\Field(type="float")
     * @Gedmo\Versioned
     */
    protected $total;

    /**
     * @MongoDB\ReferenceMany(
     *  targetDocument="AppBundle\Document\File\S3File",
     *  cascade={"persist"}
     * )
     */
    protected $invoiceFiles = array();

    public function __construct()
    {
        $this->date = \DateTime::createFromFormat('U', time());
    }

    public function getId()
    {
        return $this->id;
    }

    public function getDate()
    {
        return $this->date;
    }

    public function setDate($date)
    {
        $this->date = $date;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getInvoiceNumber()
    {
        return $this->invoiceNumber;
    }
    
    public function setInvoiceNumber($invoiceNumber)
    {
        $this->invoiceNumber = $invoiceNumber;
    }

    public function getAddress()
    {
        return $this->address;
    }

    public function setAddress(Address $address)
    {
        $this->address = $address;
    }

    public function getInvoiceItems()
    {
        return $this->invoiceItems;
    }

    public function addInvoiceItem(InvoiceItem $invoiceItem)
    {
        $this->invoiceItems[] = $invoiceItem;
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

    public function getInvoiceFiles()
    {
        return $this->invoiceFiles;
    }

    public function addInvoiceFile($invoiceFile)
    {
        $this->invoiceFiles[] = $invoiceFile;
    }

    public function hasInvoiceFile()
    {
        return count($this->getInvoiceFiles()) > 0;
    }

    public function hasInvoiceItems()
    {
        return count($this->getInvoiceItems()) > 0;
    }

    public function calculate()
    {
        $total = 0;
        foreach ($this->getInvoiceItems() as $invoiceItem) {
            $total += $invoiceItem->getTotal();
        }

        $this->setTotal($this->toTwoDp($total));
    }

    public static function generateDaviesInvoice()
    {
        $invoice = new Invoice();
        $invoice->setName('Davies Group Ltd');
        $address = new Address();
        $address->setLine1('8 Lloyds Avenue');
        $address->setCity('London');
        $address->setPostcode('EC3N 3EL');
        $invoice->setAddress($address);

        return $invoice;
    }
}
