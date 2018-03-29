<?php

namespace AppBundle\Tests\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use AppBundle\Event\PolicyEvent;
use AppBundle\Listener\DoctrinePolicyListener;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-nonet
 */
class DoctrinePolicyListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    /** @var Container */
    protected static $container;
    protected static $testUser;

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
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');
    }

    public function tearDown()
    {
    }

    public function testPolicyPreUpdate()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('policy-preupdate', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $this->assertTrue($policy->isValidPolicy());

        // policy updated
        $this->runPreUpdate($policy, $this->once(), ['potValue' => 20]);
        $this->runPreUpdate($policy, $this->once(), ['promoPotValue' => 20]);
    }

    public function testPolicyPreRemove()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyPreRemove', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $exception = false;
        try {
            static::$dm->remove($policy);
            static::$dm->flush();
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertTrue($exception);
        $this->assertPolicyExists(self::$container, $policy);
    }

    public function testPartialPolicyPreRemove()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPartialPolicyPreRemove', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);

        $exception = false;
        try {
            static::$dm->remove($policy);
            static::$dm->flush();
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertFalse($exception);
        $this->assertPolicyDoesNotExist(self::$container, $policy);
    }

    private function runPreUpdate($policy, $count, $changeSet)
    {
        $listener = $this->createListener($policy, $count, PolicyEvent::EVENT_UPDATED_POT);
        $events = new PreUpdateEventArgs($policy, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    private function createListener($policy, $count, $eventType)
    {
        $event = new PolicyEvent($policy);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
                         ->setMethods(array('dispatch'))
                         ->getMock();
        $dispatcher->expects($count)
                     ->method('dispatch')
                     ->with($eventType, $event);

        $listener = new DoctrinePolicyListener($dispatcher);

        return $listener;
    }
}
