<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Service\PushService;
use AppBundle\Document\PhonePolicy;

/**
 * @group functional-nonet
 * @group fixed
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
        $this->assertEquals(['ss' => ['display' => 'popup', 'message_type' => 'general'], 'type' => 'alert'], $data);
    }

    public function testCustomDataMessageConnected()
    {
        $data = self::$push->getCustomData(PushService::MESSAGE_CONNECTED);
        $this->assertEquals(['ss' => [
                'uri' => 'sosure://open/pot',
                'display' => 'popup',
                'refresh' => true,
                'message_type' => 'connected'
            ],
            'type' => 'alert'
        ], $data);
    }

    public function testPseudoMessagePicsure()
    {
        $data = self::$push->getCustomData(PushService::PSEUDO_MESSAGE_PICSURE);
        $this->assertEquals(['ss' => [
                'uri' => 'sosure://open/picsure',
                'refresh' => true,
                'message_type' => 'general'
            ],
            'type' => 'alert'
        ], $data);
    }

    public function testPseudoMessagePicsurePolicy()
    {
        $policy = new PhonePolicy();
        $policy->setId(rand(1, 999999));
        $data = self::$push->getCustomData(PushService::PSEUDO_MESSAGE_PICSURE, null, $policy);
        $this->assertEquals(['ss' => [
                'uri' => sprintf('sosure://open/picsure/?policy_id=%s', $policy->getId()),
                'refresh' => true,
                'message_type' => 'general'
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
                'message_type' => 'invitation'
            ],
            'type' => 'alert'
        ], $data);

        $data = self::$push->getCustomData(PushService::MESSAGE_INVITATION, ['id' => '1']);
        $this->assertEquals(['ss' => [
                'uri' => 'sosure://open/pot',
                'display' => 'popup',
                'refresh' => true,
                'message_type' => 'invitation',
                'data' => ['invitation' => ['id' => '1']],
            ],
            'type' => 'alert'
        ], $data);
    }

    public function testGcm()
    {
        $data = self::$push->generateGCMMessage(PushService::MESSAGE_GENERAL, 'foo');
        $this->assertEquals(['data' => [
            'message' => 'foo',
            'ss' => ['display' => 'popup', 'message_type' => 'general'],
            'type' => 'alert'
        ]], $data);
    }

    public function testApns()
    {
        $data = self::$push->generateAPNSMessage(PushService::MESSAGE_GENERAL, 'foo');
        $this->assertEquals([
            'aps' => ['alert' => 'foo', 'category' => 'general'],
            'ss' => ['display' => 'popup', 'message_type' => 'general'],
            'type' => 'alert'
        ], $data);
    }
}
