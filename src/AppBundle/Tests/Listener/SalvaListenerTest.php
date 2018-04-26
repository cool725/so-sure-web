<?php

namespace AppBundle\Tests\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
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
use AppBundle\Event\PolicyEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-net
 */
class SalvaListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    protected static $salvaService;
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
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
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
        $listener->onPolicySalvaIncrementEvent(new PolicyEvent($policy));

        // one updated
        $this->assertEquals(1, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));
        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_UPDATED, $data['action']);
    }

    public function testSalvaQueueUpdatedActual()
    {
        static::$redis->del(SalvaExportService::KEY_POLICY_ACTION);
        $this->assertEquals(0, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));

        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSalvaQueueUpdatedActual', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        static::$dm->flush();

        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_CREATED, $data['action']);
        $this->assertTrue($policy->isValidPolicy());
        $this->assertTrue($policy->isBillablePolicy());

        $policy->setPremiumInstallments(1);
        static::$dm->flush();

        // one updated
        $this->assertEquals(1, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));
        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_UPDATED, $data['action']);
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
        $listener->onPolicyCreatedEvent(new PolicyEvent($policy));

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
        $listener->onPolicyCancelledEvent(new PolicyEvent($policy));

        $this->assertEquals(1, static::$redis->llen(SalvaExportService::KEY_POLICY_ACTION));
        $data = unserialize(static::$redis->lpop(SalvaExportService::KEY_POLICY_ACTION));
        $this->assertEquals($policy->getId(), $data['policyId']);
        $this->assertEquals(SalvaExportService::QUEUE_CANCELLED, $data['action']);
    }
}
