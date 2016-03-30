<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use GeoJson\Geometry\Point;

/**
 * @group functional-nonet
 */
class ImeiServiceTest extends WebTestCase
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

    public function testImei()
    {
        $this->assertFalse(self::$imei->isImei(0));
        $this->assertFalse(self::$imei->isImei('0'));

        // 356938035643809 should be valid (from internet)
        $this->assertTrue(self::$imei->isImei(356938035643809));
        $this->assertTrue(self::$imei->isImei('356938035643809'));

        // 356938035643809 is valid 356938035643808 changes check digit should invalidate luhn check
        $this->assertFalse(self::$imei->isImei(356938035643808));
        $this->assertFalse(self::$imei->isImei('356938035643808'));
    }
}
