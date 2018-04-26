<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\Invoice;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\InvoiceItem;
use AppBundle\Document\Address;

/**
 * @group functional-nonet
 */
class InvoiceTest extends WebTestCase
{
    use \AppBundle\Tests\UserClassTrait;
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         /** @var DocumentManager */
         $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$dm = $dm;
    }

    public function tearDown()
    {
    }

    public function testInvoice()
    {
        $invoice = new Invoice();
        $invoice->setInvoiceNumber('ssa-000001');
        $invoice->setName('Davies');
        $address = new Address();
        $address->setLine1('123 foo');
        $address->setCity('Bar');
        $address->setPostcode('BX11LT');
        $invoice->setAddress($address);
        
        $item1 = new InvoiceItem();
        $item1->setDescription('foo');
        $item1->setUnitPrice(0.99);
        $item1->setQuantity(2);
        $invoice->addInvoiceItem($item1);

        $this->assertEquals(1.98, $item1->getTotal());
        $this->assertEquals(1.98, $invoice->getTotal());

        $item2 = new InvoiceItem();
        $item2->setDescription('bar');
        $item2->setUnitPrice(0.99);
        $item2->setQuantity(1);
        $invoice->addInvoiceItem($item2);

        $this->assertEquals(0.99, $item2->getTotal());
        $this->assertEquals(2.97, $invoice->getTotal());

        self::$dm->persist($invoice);
        self::$dm->flush();
    }
}
