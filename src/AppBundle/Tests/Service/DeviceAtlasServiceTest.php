<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group functional-paid
 */
class DeviceAtlasServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $deviceAtlas;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$deviceAtlas = self::$container->get('app.deviceatlas');
    }

    public function tearDown()
    {
    }

    public function testiPhone6()
    {
        // @codingStandardsIgnoreStart
        // http://stackoverflow.com/questions/12305566/what-is-the-ios-6-user-agent-string
        $userAgent = "Mozilla/5.0 (iPhone; CPU iPhone OS 6_0 like Mac OS X) AppleWebKit/536.26 (KHTML, like Gecko) Version/6.0 Mobile/10A5376e Safari/8536.25";
        // @codingStandardsIgnoreEnd

        $phone = self::$deviceAtlas->getPhone($this->getRequest($userAgent));
        $this->assertTrue($phone !== null);
        $this->assertEquals("Apple", $phone);
    }

    public function testiPhone5c()
    {
        // @codingStandardsIgnoreStart
        // Mozilla/5.0 (iPhone; CPU iPhone OS 7_0_1 like Mac OS X) AppleWebKit/537.51.1 (KHTML, like Gecko) Version/7.0 Mobile/11A470a Safari/9537.53
        $userAgent = "Mozilla/5.0 (iPhone; CPU iPhone OS 7_0_1 like Mac OS X) AppleWebKit/537.51.1 (KHTML, like Gecko) Version/7.0 Mobile/11A470a Safari/9537.53";
        // @codingStandardsIgnoreEnd

        $phone = self::$deviceAtlas->getPhone($this->getRequest($userAgent));
        $this->assertTrue($phone !== null);
        $this->assertEquals("Apple", $phone);
    }

    public function testNexus4()
    {
        // @codingStandardsIgnoreStart
        // http://stackoverflow.com/questions/12305566/what-is-the-ios-6-user-agent-string
        $userAgent = "Mozilla/5.0 (Linux; Android 4.3; Nexus 4 Build/JWR66Y) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/30.0.1599.82 Mobile Safari/537.36";
        // @codingStandardsIgnoreEnd

        $phone = self::$deviceAtlas->getPhone($this->getRequest($userAgent));
        $this->assertTrue($phone !== null);
        $this->assertEquals("Nexus 4", $phone->getModel());
    }

    public function testGalaxy6Edge()
    {
        // @codingStandardsIgnoreStart
        // Mozilla/5.0 (Linux; Android 5.1.1; SM-G925F Build/LMY47X) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.94 Mobile Safari/537.36
        $userAgent = "Mozilla/5.0 (Linux; Android 5.1.1; SM-G925F Build/LMY47X) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/45.0.2454.94 Mobile Safari/537.36";
        // @codingStandardsIgnoreEnd

        $phone = self::$deviceAtlas->getPhone($this->getRequest($userAgent));
        $this->assertTrue($phone !== null);
        $this->assertEquals("Galaxy S6 Edge", $phone->getModel());
    }
    
    public function testOnePlus()
    {
        // @codingStandardsIgnoreStart
        // Mozilla/5.0 (Linux; Android 6.0.1; A0001 Build/MMB29X) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.89 Mobile Safari/537.36
        $userAgent = "Mozilla/5.0 (Linux; Android 6.0.1; A0001 Build/MMB29X) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/50.0.2661.89 Mobile Safari/537.36";
        // @codingStandardsIgnoreEnd

        $phone = self::$deviceAtlas->getPhone($this->getRequest($userAgent));
        $this->assertTrue($phone !== null);
        $this->assertEquals("One", $phone->getModel());
    }

    public function testNokiaN95PopulatesMissing()
    {
        $userAgent = "Mozilla/5.0 (SymbianOS/9.2; U; Series60/3.1 NokiaN95/";

        $phone = self::$deviceAtlas->getPhone($this->getRequest($userAgent));
        $this->assertEquals('Nokia', $phone);
        $this->assertEquals(1, count(self::$deviceAtlas->getMissing()));
    }

    private function getRequest($userAgent)
    {
        $request = Request::create(
            '/',
            'GET',
            [], // $parameters
            [], // $cookies
            [], // $files
            ['HTTP_USER_AGENT' => $userAgent], // $server
            [] // $content
        );

        return $request;
    }
}
