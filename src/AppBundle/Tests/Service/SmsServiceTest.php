<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\Charge;
use AppBundle\Service\MixpanelService;
use AppBundle\Service\SmsService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\PhonePolicy;

/**
 * @group functional-nonet
 */
class SmsServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;

    /** @var SmsService */
    protected static $sms;

    /** @var Client */
    protected static $redis;
    protected static $userRepo;

    protected static $requestService;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var SmsService $sms */
        $sms = self::$container->get('app.sms');
        static::$sms = $sms;

        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        /** @var Client $redis */
        $redis = self::$container->get('snc_redis.default');
        self::$redis = $redis;
        self::$requestService = self::$container->get('app.request');
    }

    public function tearDown()
    {
    }

    public function testSend()
    {
        // self::$sms->send('+447775740466', 'test');
        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testSendTemplateCampaign()
    {
        static::$redis->del([MixpanelService::KEY_MIXPANEL_QUEUE]);

        $email = static::generateEmail('testSendTemplateCampaign', $this);
        $policy = static::createUserPolicy(true, null, false, $email);
        static::$dm->persist($policy);
        static::$dm->persist($policy->getUser());
        static::$dm->flush();

        static::$requestService->setEnvironment('prod');
        static::$sms->sendTemplate(
            static::generateRandomMobile(),
            'AppBundle:Sms:card/failedPayment-2.txt.twig',
            ['policy' => $policy],
            $policy,
            Charge::TYPE_SMS_PAYMENT,
            true
        );
        static::$requestService->setEnvironment('test');

        // person & track
        $this->assertEquals(3, static::$redis->llen(MixpanelService::KEY_MIXPANEL_QUEUE));
        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_PERSON_PROPERTIES, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_ATTRIBUTION, $data['action']);

        $data = unserialize(static::$redis->lpop(MixpanelService::KEY_MIXPANEL_QUEUE));
        $this->assertEquals(MixpanelService::QUEUE_TRACK, $data['action']);
        $this->assertEquals(MixpanelService::EVENT_SMS, $data['event']);
    }
}
