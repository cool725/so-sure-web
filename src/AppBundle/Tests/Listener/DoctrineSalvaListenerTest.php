<?php

namespace AppBundle\Tests\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use AppBundle\Event\PolicyEvent;
use AppBundle\Listener\DoctrineSalvaListener;
use AppBundle\Document\User;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePolicy;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Listener\\DoctrineSalvaListenerTest
 */
class DoctrineSalvaListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
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
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');
    }

    public function tearDown()
    {
    }

    public function testSalvaPreUpdateActive()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('salva-preupdate', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $this->assertTrue($policy->isValidPolicy());

        // policy updated
        $this->runPreUpdate($policy, $this->once(), ['phone' => ['Apple', 'Samsung']]);
        $this->runPreUpdate($policy, $this->once(), ['imei' => ['11', '12']]);
        $this->runPreUpdate($policy, $this->never(), ['potValue' => [1, 2]]);
        $this->runPreUpdate($policy, $this->once(), ['premiumInstallments' => [1, 12]]);

        $premiumA = new PhonePremium();
        $premiumA->setGwp(5.89);
        $premiumA->setIpt(0.89);
        $premiumA->setIptRate(0.12);
        $premiumA->setAnnualDiscount(10);

        $premiumB = clone $premiumA;

        $this->runPreUpdate($policy, $this->never(), ['premium' => [$premiumA, $premiumB]]);

        $premiumB = clone $premiumA;
        $premiumB->setGwp(1);
        $this->runPreUpdate($policy, $this->once(), ['premium' => [$premiumA, $premiumB]]);

        $premiumB = clone $premiumA;
        $premiumB->setIpt(1);
        $this->runPreUpdate($policy, $this->once(), ['premium' => [$premiumA, $premiumB]]);

        $premiumB = clone $premiumA;
        $premiumB->setIptRate(0.15);
        $this->runPreUpdate($policy, $this->once(), ['premium' => [$premiumA, $premiumB]]);

        $premiumB = clone $premiumA;
        $premiumB->setAnnualDiscount(0);
        $this->runPreUpdate($policy, $this->never(), ['premium' => [$premiumA, $premiumB]]);

        $premiumB = clone $premiumA;
        $premiumB->setGwp(5.891);
        $this->runPreUpdate($policy, $this->never(), ['premium' => [$premiumA, $premiumB]]);

        // user updated
        $this->runPreUpdateUser($user, $policy, $this->once(), ['firstName' => ['foo', 'bar']]);
        $this->runPreUpdateUser($user, $policy, $this->once(), ['lastName' => ['foo', 'bar']]);
        $this->runPreUpdateUser($user, $policy, $this->never(), ['email' => ['foo@', 'bar@']]);
    }
    
    public function testSalvaPreUpdateUnpaid()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSalvaPreUpdateUnpaid', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_UNPAID);

        $this->assertTrue($policy->isValidPolicy());

        // policy updated
        $this->runPreUpdate($policy, $this->once(), ['phone' => ['Apple', 'Samsung']]);
        $this->runPreUpdate($policy, $this->once(), ['imei' => ['11', '12']]);
        //$this->runPreUpdate($policy, $this->once(), ['premium' => [1, 2]]);
        $this->runPreUpdate($policy, $this->never(), ['potValue' => [1, 2]]);

        // user updated
        $this->runPreUpdateUser($user, $policy, $this->once(), ['firstName' => ['foo', 'bar']]);
        $this->runPreUpdateUser($user, $policy, $this->once(), ['lastName' => ['foo', 'bar']]);
        $this->runPreUpdateUser($user, $policy, $this->never(), ['email' => ['foo@', 'bar@']]);
    }

    public function testSalvaPreUpdatePending()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSalvaPreUpdatePending', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_PENDING);

        $this->assertTrue($policy->isValidPolicy());

        // policy updated
        $this->runPreUpdate($policy, $this->never(), ['phone' => ['Apple', 'Samsung']]);
        $this->runPreUpdate($policy, $this->never(), ['imei' => ['11', '12']]);
        $this->runPreUpdate($policy, $this->never(), ['premium' => [1, 2]]);
        $this->runPreUpdate($policy, $this->never(), ['potValue' => [1, 2]]);

        // user updated
        $this->runPreUpdateUser($user, $policy, $this->never(), ['firstName' => ['foo', 'bar']]);
        $this->runPreUpdateUser($user, $policy, $this->never(), ['lastName' => ['foo', 'bar']]);
        $this->runPreUpdateUser($user, $policy, $this->never(), ['email' => ['foo@', 'bar@']]);
    }

    private function runPreUpdate($policy, $count, $changeSet, $event = null)
    {
        if (!$event) {
            $event = PolicyEvent::EVENT_SALVA_INCREMENT;
        }
        $listener = $this->createListener($policy, $count, $event);
        $events = new PreUpdateEventArgs($policy, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    private function runPreUpdateUser($user, $policy, $count, $changeSet)
    {
        $listener = $this->createListener($policy, $count, PolicyEvent::EVENT_SALVA_INCREMENT);
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
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

        $listener = new DoctrineSalvaListener($dispatcher, 'test', static::$container->get('logger'));

        return $listener;
    }
}
