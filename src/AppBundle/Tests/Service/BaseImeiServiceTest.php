<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\LostPhone;
use AppBundle\Document\Phone;
use GeoJson\Geometry\Point;

/**
 * @group functional-nonet
 */
class ReceperioServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $imei;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$imei = self::$container->get('app.imei');
    }

    public function tearDown()
    {
    }

    public function testCheckImei()
    {
        $this->assertTrue(self::$imei->checkImei(new Phone(), 356938035643809));
    }

    public function testIsLostImeiInvalid()
    {
        $this->assertFalse(self::$imei->isLostImei(0));
    }

    public function testIsLostImeiValid()
    {
        $imeiNumber = rand(1, 999999);
        $lost = new LostPhone();
        $lost->setImei($imeiNumber);
        static::$dm->persist($lost);
        static::$dm->flush();

        //$imeiService = self::$container->get('app.imei');
        //$imeiService->setDm(static::$dm);
        //$this->assertTrue($imeiService->isLostImei($imeiNumber));
        $this->assertTrue(self::$imei->isLostImei($imeiNumber));
    }
}
