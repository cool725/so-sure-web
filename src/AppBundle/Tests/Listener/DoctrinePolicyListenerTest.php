<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Classes\Premium;
use AppBundle\Document\Address;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Document\PhonePremium;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\CardEvent;
use Doctrine\Common\Annotations\Reader;
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
    /** @var DocumentManager */
    protected static $dm;
    protected static $testUser;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        /** @var Container $container */
        $container = $kernel->getContainer();
        self::$container = $container;

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
        static::$dm->clear();
    }

    public function testPolicyPreUpdatePot()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyPreUpdatePot', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $this->assertTrue($policy->isValidPolicy());

        // policy updated
        $this->runPreUpdate($policy, $this->once(), ['potValue' => [null, 20]]);
        $this->runPreUpdate($policy, $this->once(), ['promoPotValue' => [null, 20]]);
    }

    public function testPolicyPreUpdatePremium()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyPreUpdatePremium', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $this->assertTrue($policy->isValidPolicy());

        $premium = new PhonePremium();
        $premium->setGwp(5);
        $premium->setIpt(1);
        $premium->setIptRate(0.12);
        $premiumChanged = new PhonePremium();
        $premium->setGwp(6);
        $premium->setIpt(1);
        $premium->setIptRate(0.12);
        $this->runPreUpdatePremium($policy, $this->once(), ['premium' => [$premium, $premiumChanged]]);
        $this->runPreUpdatePremium($policy, $this->never(), ['premium' => [$premium, $premium]]);
    }

    public function testPolicyPreUpdateBilling()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyPreUpdateBilling', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $this->assertTrue($policy->isValidPolicy());

        $address = new Address();
        $address->setLine1('123');
        $address->setPostcode('BX1 1LT');

        $addressChanged = new Address();
        $addressChanged->setLine1('1234');
        $addressChanged->setPostcode('BX1 1LT');

        $this->runPreUpdateBilling($policy, $this->once(), ['billing' => [$address, $addressChanged]]);
        $this->runPreUpdateBilling($policy, $this->never(), ['billing' => [$address, $address]]);
    }

    public function testPolicyPreUpdateStatus()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPolicyPreUpdateStatus', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $this->assertTrue($policy->isValidPolicy());

        $this->runPreUpdateStatus(
            $policy,
            $this->once(),
            ['status' => [PhonePolicy::STATUS_ACTIVE, PhonePolicy::STATUS_UNPAID]],
            PhonePolicy::STATUS_ACTIVE
        );
        $this->runPreUpdateStatus(
            $policy,
            $this->never(),
            ['status' => [PhonePolicy::STATUS_ACTIVE, PhonePolicy::STATUS_ACTIVE]],
            PhonePolicy::STATUS_ACTIVE
        );
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

    public function testPreUpdateJudo()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPreUpdateJudo', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        self::setPaymentMethodForPolicy($policy);

        $this->assertTrue($policy->isValidPolicy());

        $this->runPreUpdateStatus(
            $policy,
            $this->once(),
            ['status' => [PhonePolicy::STATUS_ACTIVE, PhonePolicy::STATUS_UNPAID]],
            PhonePolicy::STATUS_ACTIVE
        );
        $this->runPreUpdateStatus(
            $policy,
            $this->never(),
            ['status' => [PhonePolicy::STATUS_ACTIVE, PhonePolicy::STATUS_ACTIVE]],
            PhonePolicy::STATUS_ACTIVE
        );

        /** @var JudoPaymentMethod $judo */
        $judo = $policy->getPaymentMethod();

        $listener = $this->createCardListener(
            $policy,
            $this->exactly(1),
            CardEvent::EVENT_UPDATED
        );

        $updatedJudo = clone $judo;
        $account = ['type' => '2', 'lastfour' => '1234', 'endDate' => '1225'];
        $updatedJudo->addCardTokenArray(random_int(1, 999999), $account);
        $changeSet = ['paymentMethod' => [$judo, $updatedJudo]];
        $events = new PreUpdateEventArgs($policy, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $changeSet = ['paymentMethod' => [$updatedJudo, $updatedJudo]];
        $events = new PreUpdateEventArgs($policy, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateBankAccountSortCodePolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPreUpdateBankAccountSortCodePolicy', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        /** @var BacsPaymentMethod $bacs */
        $bacs = self::setBacsPaymentMethodForPolicy($policy);

        $this->assertTrue($policy->isValidPolicy());

        $listener = $this->createBacsEventListener(
            $policy,
            $bacs->getBankAccount(),
            $this->exactly(1),
            BacsEvent::EVENT_UPDATED
        );

        $updatedBacs = clone $bacs;
        $updatedBankAccount = clone $bacs->getBankAccount();
        $updatedBankAccount->setSortCode('000098');
        $updatedBacs->setBankAccount($updatedBankAccount);
        $changeSet = ['paymentMethod' => [$bacs, $updatedBacs]];
        $events = new PreUpdateEventArgs($policy, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdatePaymentMethodPolicy()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPreUpdatePaymentMethodPolicy', $this),
            'bar'
        );
        $judoPolicy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        $bacsPolicy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($judoPolicy);
        static::$policyService->create($bacsPolicy);
        static::$policyService->setEnvironment('test');
        $judoPolicy->setStatus(PhonePolicy::STATUS_ACTIVE);
        $bacsPolicy->setStatus(PhonePolicy::STATUS_ACTIVE);
        /** @var JudoPaymentMethod $judo */
        $judo = self::setPaymentMethodForPolicy($judoPolicy);
        /** @var BacsPaymentMethod $bacs */
        $bacs = self::setBacsPaymentMethodForPolicy($bacsPolicy);

        $listener = $this->createPolicyEventListener(
            $judoPolicy,
            $this->exactly(1),
            PolicyEvent::EVENT_PAYMENT_METHOD_CHANGED,
            'judo'
        );

        $changeSet = ['paymentMethod' => [$judo, $bacs]];
        $events = new PreUpdateEventArgs($judoPolicy, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $changeSet = ['paymentMethod' => [$judo, $judo]];
        $events = new PreUpdateEventArgs($judoPolicy, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $listener = $this->createPolicyEventListener(
            $bacsPolicy,
            $this->exactly(1),
            PolicyEvent::EVENT_PAYMENT_METHOD_CHANGED,
            'bacs'
        );

        $changeSet = ['paymentMethod' => [$bacs, $judo]];
        $events = new PreUpdateEventArgs($bacsPolicy, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $changeSet = ['paymentMethod' => [$bacs, $bacs]];
        $events = new PreUpdateEventArgs($bacsPolicy, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    private function runPreUpdate($policy, $count, $changeSet)
    {
        $listener = $this->createListener($policy, $count, PolicyEvent::EVENT_UPDATED_POT);
        $events = new PreUpdateEventArgs($policy, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    private function runPreUpdatePremium($policy, $count, $changeSet)
    {
        $listener = $this->createListener($policy, $count, PolicyEvent::EVENT_UPDATED_PREMIUM);
        $events = new PreUpdateEventArgs($policy, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    private function runPreUpdateBilling($policy, $count, $changeSet)
    {
        $listener = $this->createListener($policy, $count, PolicyEvent::EVENT_UPDATED_BILLING);
        $events = new PreUpdateEventArgs($policy, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    private function runPreUpdateStatus($policy, $count, $changeSet, $previousStatus)
    {
        $listener = $this->createListener($policy, $count, PolicyEvent::EVENT_UPDATED_STATUS, $previousStatus);
        $events = new PreUpdateEventArgs($policy, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    private function createListener($policy, $count, $eventType, $previousStatus = null)
    {
        $event = new PolicyEvent($policy);
        if ($previousStatus) {
            $event->setPreviousStatus($previousStatus);
        }

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
                         ->setMethods(array('dispatch'))
                         ->getMock();
        $dispatcher->expects($count)
                     ->method('dispatch')
                     ->with($eventType, $event);

        $listener = new DoctrinePolicyListener($dispatcher, "test");
        /** @var Reader $reader */
        $reader = static::$container->get('annotations.reader');
        $listener->setReader($reader);

        return $listener;
    }

    private function createCardListener($policy, $count, $eventType)
    {
        $event = new CardEvent();
        $event->setPolicy($policy);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
            ->setMethods(array('dispatch'))
            ->getMock();
        $dispatcher->expects($count)
            ->method('dispatch')
            ->with($eventType, $event);

        $listener = new DoctrinePolicyListener($dispatcher, "test");
        /** @var Reader $reader */
        $reader = static::$container->get('annotations.reader');
        $listener->setReader($reader);

        return $listener;
    }

    private function createBacsEventListener(Policy $policy, $bankAccount, $count, $eventType)
    {
        \AppBundle\Classes\NoOp::ignore([$eventType]);
        $event = new BacsEvent($bankAccount);
        $event->setPolicy($policy);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
            ->setMethods(array('dispatch'))
            ->getMock();
        $dispatcher->expects($count)
            ->method('dispatch')
            ->with($eventType, $event);

        $listener = new DoctrinePolicyListener($dispatcher, "test");
        /** @var Reader $reader */
        $reader = static::$container->get('annotations.reader');
        $listener->setReader($reader);

        return $listener;
    }

    private function createPolicyEventListener(
        Policy $policy,
        $count,
        $eventType,
        $previousPaymentMethod = null
    ) {
        $event = new PolicyEvent($policy);
        if ($previousPaymentMethod) {
            $event->setPreviousPaymentMethod($previousPaymentMethod);
        }

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
            ->setMethods(array('dispatch'))
            ->getMock();
        $dispatcher->expects($count)
            ->method('dispatch')
            ->with($eventType, $event);

        $listener = new DoctrinePolicyListener($dispatcher, "test");
        /** @var Reader $reader */
        $reader = static::$container->get('annotations.reader');
        $listener->setReader($reader);

        return $listener;
    }
}
