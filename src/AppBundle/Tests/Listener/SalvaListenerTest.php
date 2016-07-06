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
use AppBundle\Service\SalvaExportService;
use AppBundle\Listener\SalvaListener;
use AppBundle\Event\SalvaPolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-net
 */
class SalvaListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $userRepo;
    protected static $userManager;
    protected static $salvaService;
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
        self::$policyService = self::$container->get('app.policy');
        self::$salvaService = self::$container->get('app.salva');
        self::$redis = self::$container->get('snc_redis.default');
    }

    public function tearDown()
    {
    }

    public function testSalvaQueueUpdated()
    {
        static::$redis->del(SalvaExportService::KEY_POLICY_ACTION);
        $this->assertEquals(0, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-queue-updated', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_CREATED, $data['action']);
        $this->assertTrue($policy->isValidPolicy());

        $listener = new SalvaListener(static::$salvaService);
        $listener->onSalvaPolicyUpdatedEvent(new SalvaPolicyEvent($policy));

        // one cancel, one create
        $this->assertEquals(2, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));
        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_CANCELLED, $data['action']);

        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_CREATED, $data['action']);
    }

    public function testSalvaQueueCreated()
    {
        static::$redis->del(SalvaExportService::KEY_POLICY_ACTION);
        $this->assertEquals(0, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-queue-created', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_CREATED, $data['action']);
        $this->assertTrue($policy->isValidPolicy());

        $listener = new SalvaListener(static::$salvaService);
        $listener->onSalvaPolicyCreatedEvent(new SalvaPolicyEvent($policy));

        $this->assertEquals(1, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));
        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_CREATED, $data['action']);
    }

    public function testSalvaQueueCancelled()
    {
        static::$redis->del(SalvaExportService::KEY_POLICY_ACTION);
        $this->assertEquals(0, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-queue-cancelled', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $this->assertEquals(1, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));
        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_CREATED, $data['action']);

        $this->assertTrue($policy->isValidPolicy());
        
        $listener = new SalvaListener(static::$salvaService);
        $listener->onSalvaPolicyCancelledEvent(new SalvaPolicyEvent($policy));

        $this->assertEquals(1, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));
        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_CANCELLED, $data['action']);
    }
}
