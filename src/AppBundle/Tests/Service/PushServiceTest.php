<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Service\PushService;

/**
 * @group functional-nonet
 */
class PushServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $push;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$push = self::$container->get('app.push');
    }

    public function tearDown()
    {
    }

    public function testCustomDataMessageGeneral()
    {
        $data = self::$push->getCustomData(PushService::MESSAGE_GENERAL);
        $this->assertEquals(['ss' => ['display' => 'popup'], 'type' => 'alert'], $data);
    }

    public function testCustomDataMessageConnected()
    {
        $data = self::$push->getCustomData(PushService::MESSAGE_CONNECTED);
        $this->assertEquals(['ss' => [
                'uri' => 'sosure://open/pot',
                'display' => 'popup',
                'refresh' => true,
            ],
            'type' => 'alert'
        ], $data);
    }

    public function testCustomDataMessageInvitation()
    {
        $data = self::$push->getCustomData(PushService::MESSAGE_INVITATION);
        $this->assertEquals(['ss' => [
                'uri' => 'sosure://open/pot',
                'display' => 'popup',
                'refresh' => true,
            ],
            'type' => 'alert'
        ], $data);
    }

    public function testGcm()
    {
        $data = self::$push->generateGCMMessage(PushService::MESSAGE_GENERAL, 'foo');
        $this->assertEquals(['data' => [
            'message' => 'foo',
            'ss' => ['display' => 'popup'],
            'type' => 'alert'
        ]], $data);
    }

    public function testApns()
    {
        $data = self::$push->generateAPNSMessage(PushService::MESSAGE_GENERAL, 'foo');
        $this->assertEquals([
            'aps' => ['alert' => 'foo'],
            'ss' => ['display' => 'popup'],
            'type' => 'alert'
        ], $data);
    }
}
