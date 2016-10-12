<?php

namespace AppBundle\Tests\Listener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\UserListener;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\UserEvent;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Service\IntercomService;
use AppBundle\Listener\IntercomListener;
use AppBundle\Event\PolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-net
 */
class IntercomListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $userRepo;
    protected static $userManager;
    protected static $intercomService;
    protected static $policyService;
    protected static $redis;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$intercomService = self::$container->get('app.intercom');
        self::$policyService = self::$container->get('app.policy');
        self::$redis = self::$container->get('snc_redis.default');
    }

    public function tearDown()
    {
    }

    public function testIntercomPolicyActual()
    {
        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('intercom-queue-actual', $this),
            'bar'
        );
        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);

        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $this->assertTrue($policy->isValidPolicy());

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }
    
    public function testIntercomQueueUpdated()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('intercom-queue-updated', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPolicyUpdatedEvent(new PolicyEvent($policy));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }

    public function testIntercomQueueCreated()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('intercom-queue-created', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPolicyCreatedEvent(new PolicyEvent($policy));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }

    public function testIntercomQueueCancelled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('intercom-queue-cancelled', $this),
            'bar'
        );
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setId(rand(1, 99999));

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }

    public function testIntercomQueueUser()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('intercom-queue-user', $this),
            'bar'
        );

        static::$redis->del(IntercomService::KEY_INTERCOM_QUEUE);
        $this->assertEquals(0, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));

        $listener = new IntercomListener(static::$intercomService);
        $listener->onUserUpdatedEvent(new UserEvent($user));

        $this->assertEquals(1, static::$redis->llen(IntercomService::KEY_INTERCOM_QUEUE));
        $data = unserialize(static::$redis->lpop(IntercomService::KEY_INTERCOM_QUEUE));
        $this->assertEquals($user->getId(), $data['userId']);
    }
}
