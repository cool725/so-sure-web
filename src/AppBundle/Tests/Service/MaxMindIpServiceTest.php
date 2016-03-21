<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @group functional
 */
class MaxMindServiceIpTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $geoip;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$geoip = self::$container->get('app.geoip');
    }

    public function tearDown()
    {
    }

    public function testIp()
    {
        $data = self::$geoip->find('62.253.24.186');
        $this->assertEquals('GB', self::$geoip->getCountry());
        $this->assertEquals(51.5, $data->location->latitude);
        $this->assertEquals(-0.13, $data->location->longitude);
        $this->assertEquals('{"type":"Point","coordinates":[51.5,-0.13]}', self::$geoip->getGeoJson());
    }
}
