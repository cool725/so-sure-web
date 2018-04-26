<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Event\BacsEvent;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-nonet
 */
class DoctrineUserListenerTest extends WebTestCase
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

    public function testPreUpdate()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('pre', $this));
        static::$dm->persist($user);
        $listener = new DoctrineUserListener(null, self::$container->get('logger'));

        $changeSet = ['confirmationToken' => ['123', null], 'passwordRequestedAt' => [new \DateTime(), null]];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $this->assertTrue($events->getDocument()->getEmailVerified());
    }

    public function testPreUpdatePassword()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('testPreUpdatePassword', $this));
        static::$dm->persist($user);
        $listener = $this->createUserEventListener($user, $this->once(), UserEvent::EVENT_PASSWORD_CHANGED);

        $changeSet = ['password' => ['a', 'b']];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $this->assertTrue(count($events->getDocument()->getPreviousPasswords()) > 0);
    }

    public function testPreUpdateName()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('testPreUpdateName', $this));
        static::$dm->persist($user);
        $listener = $this->createUserEventListener($user, $this->never(), UserEvent::EVENT_NAME_UPDATED);

        $changeSet = ['firstName' => ['a', 'a']];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $listener = $this->createUserEventListener($user, $this->once(), UserEvent::EVENT_NAME_UPDATED);

        $changeSet = ['firstName' => ['a', 'b']];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $listener = $this->createUserEventListener($user, $this->never(), UserEvent::EVENT_NAME_UPDATED);

        $changeSet = ['lastName' => ['a', 'a']];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $listener = $this->createUserEventListener($user, $this->once(), UserEvent::EVENT_NAME_UPDATED);

        $changeSet = ['lastName' => ['a', 'b']];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    private function account($email)
    {
        $user = new User();
        $bankAccount = new BankAccount();
        $bankAccount->setSortCode('000099');
        $bankAccount->setAccountNumber('12345678');
        $bacs = new BacsPaymentMethod();
        $bacs->setBankAccount($bankAccount);
        $user->setPaymentMethod($bacs);
        $user->setEmail(static::generateEmail($email, $this));
        static::$dm->persist($user);

        return $user;
    }

    public function testPreUpdateBankAccountSortCode()
    {
        $user = $this->account('testPreUpdateBankAccountSortCode');
        $bacs = $user->getPaymentMethod();

        $listener = $this->createBacsEventListener(
            $bacs->getBankAccount(),
            $user->getId(),
            $this->once(),
            BacsEvent::EVENT_UPDATED
        );

        $updatedBacs = clone $bacs;
        $updatedBankAccount = clone $bacs->getBankAccount();
        $updatedBankAccount->setSortCode('000098');
        $updatedBacs->setBankAccount($updatedBankAccount);
        $changeSet = ['paymentMethod' => [$bacs, $updatedBacs]];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateBankAccountSameSortCode()
    {
        $user = $this->account('testPreUpdateBankAccountSameSortCode');
        $bacs = $user->getPaymentMethod();

        $listener = $this->createBacsEventListener(
            $bacs->getBankAccount(),
            $user->getId(),
            $this->never(),
            BacsEvent::EVENT_UPDATED
        );

        $updatedBacs = clone $bacs;
        $updatedBankAccount = clone $bacs->getBankAccount();
        $updatedBankAccount->setSortCode('000099');
        $updatedBacs->setBankAccount($updatedBankAccount);
        $changeSet = ['paymentMethod' => [$bacs, $updatedBacs]];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateBankAccountNumber()
    {
        $user = $this->account('testPreUpdateBankAccountNumber');
        $bacs = $user->getPaymentMethod();

        $listener = $this->createBacsEventListener(
            $bacs->getBankAccount(),
            $user->getId(),
            $this->once(),
            BacsEvent::EVENT_UPDATED
        );

        $updatedBacs = clone $bacs;
        $updatedBankAccount = clone $bacs->getBankAccount();
        $updatedBankAccount->setAccountNumber('87654321');
        $updatedBacs->setBankAccount($updatedBankAccount);
        $changeSet = ['paymentMethod' => [$bacs, $updatedBacs]];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateBankAccountSameNumber()
    {
        $user = $this->account('testPreUpdateBankAccountSameNumber');
        $bacs = $user->getPaymentMethod();

        $listener = $this->createBacsEventListener(
            $bacs->getBankAccount(),
            $user->getId(),
            $this->never(),
            BacsEvent::EVENT_UPDATED
        );

        $updatedBacs = clone $bacs;
        $updatedBankAccount = clone $bacs->getBankAccount();
        $updatedBankAccount->setAccountNumber('12345678');
        $updatedBacs->setBankAccount($updatedBankAccount);
        $changeSet = ['paymentMethod' => [$bacs, $updatedBacs]];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateBankAccountReference()
    {
        $user = $this->account('testPreUpdateBankAccountReference');
        $bacs = $user->getPaymentMethod();

        $listener = $this->createBacsEventListener(
            $bacs->getBankAccount(),
            $user->getId(),
            $this->once(),
            BacsEvent::EVENT_UPDATED
        );

        $updatedBacs = clone $bacs;
        $updatedBankAccount = clone $bacs->getBankAccount();
        $updatedBankAccount->setReference('999');
        $updatedBacs->setBankAccount($updatedBankAccount);
        $changeSet = ['paymentMethod' => [$bacs, $updatedBacs]];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateBankAccountName()
    {
        $user = $this->account('testPreUpdateBankAccountName');
        $bacs = $user->getPaymentMethod();

        $listener = $this->createBacsEventListener(
            $bacs->getBankAccount(),
            $user->getId(),
            $this->once(),
            BacsEvent::EVENT_UPDATED
        );

        $updatedBacs = clone $bacs;
        $updatedBankAccount = clone $bacs->getBankAccount();
        $updatedBankAccount->setAccountName('a boo');
        $updatedBacs->setBankAccount($updatedBankAccount);
        $changeSet = ['paymentMethod' => [$bacs, $updatedBacs]];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPostUpdate()
    {
        $user = new User();
        $user->setEmail('dul1@listener.so-sure.com');
        $listener = $this->createUserEventListener($user, $this->once(), UserEvent::EVENT_UPDATED);
    
        $events = new LifecycleEventArgs($user, self::$dm);
        $listener->postUpdate($events);
    }

    public function testPostPersist()
    {
        $user = new User();
        $user->setEmail('dul2@listener.so-sure.com');
        $listener = $this->createUserEventListener($user, $this->once(), UserEvent::EVENT_CREATED);
    
        $events = new LifecycleEventArgs($user, self::$dm);
        $listener->postPersist($events);
    }

    public function testPreUpdateUserEmail()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('user-email', $this));
        static::$dm->persist($user);
        $listener = $this->createUserEmailEventListener(
            $user,
            static::generateEmail('user-email', $this),
            $this->once(),
            UserEmailEvent::EVENT_CHANGED
        );

        $changeSet = ['email' => [
            static::generateEmail('user-email', $this),
            static::generateEmail('user-email-new', $this)
        ]];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateUserEmailSame()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('testPreUpdateUserEmailSame', $this));
        static::$dm->persist($user);
        $listener = $this->createUserEmailEventListener(
            $user,
            static::generateEmail('testPreUpdateUserEmailSame', $this),
            $this->never(),
            UserEmailEvent::EVENT_CHANGED
        );

        $changeSet = ['email' => [
            static::generateEmail('testPreUpdateUserEmailSame', $this),
            static::generateEmail('testPreUpdateUserEmailSame', $this)
        ]];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    private function createUserEventListener($user, $count, $eventType)
    {
        $event = new UserEvent($user);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
                         ->setMethods(array('dispatch'))
                         ->getMock();
        $dispatcher->expects($count)
                     ->method('dispatch')
                     ->with($eventType, $event);

        $listener = new DoctrineUserListener($dispatcher, self::$container->get('logger'));

        return $listener;
    }

    private function createBacsEventListener($bankAccount, $id, $count, $eventType)
    {
        $event = new BacsEvent($bankAccount, $id);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
            ->setMethods(array('dispatch'))
            ->getMock();
        $dispatcher->expects($count)
            ->method('dispatch')
            ->with($eventType, $event);

        $listener = new DoctrineUserListener($dispatcher, self::$container->get('logger'));

        return $listener;
    }

    private function createUserEmailEventListener($user, $oldEmail, $count, $eventType)
    {
        $event = new UserEmailEvent($user, $oldEmail);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
                         ->setMethods(array('dispatch'))
                         ->getMock();
        $dispatcher->expects($count)
                     ->method('dispatch')
                     ->with($eventType, $event);

        $listener = new DoctrineUserListener($dispatcher, self::$container->get('logger'));

        return $listener;
    }

    public function testUpdateUserEmailChanged()
    {
        $listener = $this->getMockBuilder('UserListener')
                         ->setMethods(array('onUserEmailChangedEvent'))
                         ->getMock();
        $listener->expects($this->once())
                     ->method('onUserEmailChangedEvent');

        $dispatcher = static::$container->get('event_dispatcher');
        $dispatcher->addListener(UserEmailEvent::EVENT_CHANGED, array($listener, 'onUserEmailChangedEvent'));

        $user = static::createUser(
            self::$userManager,
            static::generateEmail('testUpdateUserEmailChanged-init', $this),
            'foo'
        );
        $user->setEmail(static::generateEmail('testUpdateUserEmailChanged', $this));
        static::$dm->flush();
    }

    public function testUpdateUserEmailChangedButSame()
    {
        $listener = $this->getMockBuilder('UserListener')
                         ->setMethods(array('onUserEmailChangedEvent'))
                         ->getMock();
        $listener->expects($this->never())
                     ->method('onUserEmailChangedEvent');

        $dispatcher = static::$container->get('event_dispatcher');
        $dispatcher->addListener(UserEmailEvent::EVENT_CHANGED, array($listener, 'onUserEmailChangedEvent'));

        $user = static::createUser(
            self::$userManager,
            static::generateEmail('testUpdateUserEmailChangedButSame', $this),
            'foo'
        );
        // same but different
        $user->setEmail(mb_strtolower(static::generateEmail('testUpdateUserEmailChangedButSame', $this)));
        static::$dm->flush();
    }

    public function testUserPolicyPreRemove()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testUserPolicyPreRemove', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);

        $exception = false;
        try {
            static::$dm->remove($user);
            static::$dm->flush();
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertTrue($exception);
        $this->assertUserExists(self::$container, $user);
    }

    public function testUserPartialPolicyPreRemove()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testUserPartialPolicyPreRemove', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, $this->getRandomPhone(static::$dm), null, true);

        $exception = false;
        try {
            static::$dm->remove($user);
            static::$dm->flush();
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertFalse($exception);
        $this->assertUserDoesNotExist(self::$container, $user);
    }
}
