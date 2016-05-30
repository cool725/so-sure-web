<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use GeoJson\Geometry\Point;

/**
 * @group functional-paid
 */
class ReceperioServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $imei;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$imei = self::$container->get('app.imei');
    }

    public function tearDown()
    {
    }

    public function testPaidCheckImei()
    {
        // Found on interenet, valid imei, but lost/stolen
        $this->assertFalse(self::$imei->checkImei(new Phone(), 356938035643809));

        // Patrick's imei
        $this->assertTrue(self::$imei->checkImei(new Phone(), 355424073417084));
    }
}
