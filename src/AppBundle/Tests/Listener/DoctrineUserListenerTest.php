<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Charge;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Event\BacsEvent;
use AppBundle\Event\CardEvent;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
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
use Tree\Fixture\Transport\Car;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Listener\\DoctrineUserListenerTest
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
    /** @var LoggerInterface */
    protected static $logger;

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
        /** @var LoggerInterface $logger */
        $logger = self::$container->get('logger');
        self::$logger = $logger;
    }

    public function tearDown()
    {
    }

    public function testPreUpdate()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('pre', $this));
        static::$dm->persist($user);
        $listener = new DoctrineUserListener(null, static::$logger);
        $reader = new AnnotationReader();
        $listener->setReader($reader);

        $changeSet = [
            'confirmationToken' => ['123', null],
            'passwordRequestedAt' => [\DateTime::createFromFormat('U', time()), null]
        ];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);

        /** @var User $userListener */
        $userListener = $events->getDocument();
        $this->assertTrue($userListener->getEmailVerified());
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

        /** @var User $userListener */
        $userListener = $events->getDocument();
        $this->assertTrue(count($userListener->getPreviousPasswords()) > 0);
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

        $listener = $this->createUserEventListener(
            $user,
            $this->exactly(2),
            UserEvent::EVENT_NAME_UPDATED,
            UserEvent::EVENT_UPDATED_INTERCOM
        );

        $changeSet = ['firstName' => ['a', 'b']];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $listener = $this->createUserEventListener($user, $this->never(), UserEvent::EVENT_NAME_UPDATED);

        $changeSet = ['lastName' => ['a', 'a']];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);

        $listener = $this->createUserEventListener(
            $user,
            $this->exactly(2),
            UserEvent::EVENT_NAME_UPDATED,
            UserEvent::EVENT_UPDATED_INTERCOM
        );

        $changeSet = ['lastName' => ['a', 'b']];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateEmail()
    {
        $email = static::generateEmail('testPreUpdateEmail', $this);
        $emailNew = static::generateEmail('testPreUpdateEmail-new', $this);
        $user = new User();
        $user->setEmail(static::generateEmail($email, $this));
        static::$dm->persist($user);

        $listener = $this->createUserEventListener($user, $this->never(), UserEvent::EVENT_UPDATED_INTERCOM);
        $listenerLink = $this->createUserEventListener($user, $this->never(), UserEvent::EVENT_UPDATED_INVITATION_LINK);

        $changeSet = ['email' => [$email, $email]];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
        $listenerLink->preUpdate($events);

        $listener = $this->createUserEmailEventListeners($user, $email);

        $changeSet = ['email' => [$email, $emailNew]];
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
        $user->setEmail(static::generateEmail($email, $this));
        static::$dm->persist($user);

        return $user;
    }

    private function judoAccount($email)
    {
        $user = new User();
        $account = ['type' => '1', 'lastfour' => '1234', 'endDate' => '1225'];
        $judo = new JudoPaymentMethod();
        $judo->addCardTokenArray(random_int(1, 999999), $account);
        $user->setEmail(static::generateEmail($email, $this));
        static::$dm->persist($user);

        return $user;
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
        $listener = $this->createUserEmailEventListeners(
            $user,
            static::generateEmail('user-email', $this)
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

    private function createUserEventListener(
        $user,
        $count,
        $eventType,
        $eventType2 = null,
        $eventType3 = null,
        $previousPaymentMethod = null
    ) {
        $event1 = new UserEvent($user);
        if ($previousPaymentMethod) {
            $event1->setPreviousPaymentMethod($previousPaymentMethod);
        }
        $event = new UserEvent($user);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
             ->setMethods(array('dispatch'))
             ->getMock();
        if ($eventType3) {
            $dispatcher->expects($count)
                ->method('dispatch')
                ->withConsecutive([$eventType, $event1], [$eventType2, $event], [$eventType3, $event]);
        } elseif ($eventType2) {
            $dispatcher->expects($count)
                ->method('dispatch')
                ->withConsecutive([$eventType, $event1], [$eventType2, $event]);
        } else {
            $dispatcher->expects($count)
                ->method('dispatch')
                ->with($eventType, $event1);
        }

        $listener = new DoctrineUserListener($dispatcher, static::$logger);
        /** @var Reader $reader */
        $reader = static::$container->get('annotations.reader');
        $listener->setReader($reader);

        return $listener;
    }

    private function createUserEmailEventListeners($user, $oldEmail)
    {
        $event = new UserEvent($user);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
            ->setMethods(array('dispatch'))
            ->getMock();
        $dispatcher->expects($this->exactly(3))
            ->method('dispatch')
            ->withConsecutive(
                [UserEmailEvent::EVENT_CHANGED, new UserEmailEvent($user, $oldEmail)],
                [UserEvent::EVENT_UPDATED_INTERCOM, $event],
                [UserEvent::EVENT_UPDATED_INVITATION_LINK, $event]
            );

        $listener = new DoctrineUserListener($dispatcher, static::$logger);
        /** @var Reader $reader */
        $reader = static::$container->get('annotations.reader');
        $listener->setReader($reader);

        return $listener;
    }

    private function createBacsEventListener($user, $bankAccount, $count, $eventType, $eventType2 = null)
    {
        \AppBundle\Classes\NoOp::ignore([$eventType]);
        $event = new BacsEvent($bankAccount);
        $event->setUser($user);
        $userEvent = new UserEvent($user);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
            ->setMethods(array('dispatch'))
            ->getMock();
        if ($eventType2) {
            $dispatcher->expects($count)
            ->method('dispatch')
            ->withConsecutive([$eventType, $event], [$eventType2, $userEvent]);
        } else {
            $dispatcher->expects($count)
                ->method('dispatch')
                ->with($eventType, $event);
        }

        $listener = new DoctrineUserListener($dispatcher, static::$logger);
        /** @var Reader $reader */
        $reader = static::$container->get('annotations.reader');
        $listener->setReader($reader);

        return $listener;
    }

    private function createCardEventListener($user, $count, $eventType, $eventType2 = null)
    {
        $event = new CardEvent();
        $event->setUser($user);
        $userEvent = new UserEvent($user);
        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
            ->setMethods(array('dispatch'))
            ->getMock();

        if ($eventType2) {
            $dispatcher->expects($count)
                ->method('dispatch')
                ->withConsecutive([$eventType, $event], [$eventType2, $userEvent]);
        } else {
            $dispatcher->expects($count)
                ->method('dispatch')
                ->with($eventType, $event);
        }

        $listener = new DoctrineUserListener($dispatcher, static::$logger);
        /** @var Reader $reader */
        $reader = static::$container->get('annotations.reader');
        $listener->setReader($reader);

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

        $listener = new DoctrineUserListener($dispatcher, static::$logger);
        /** @var Reader $reader */
        $reader = static::$container->get('annotations.reader');
        $listener->setReader($reader);

        return $listener;
    }

    public function testUpdateUserEmailChanged()
    {
        $listener = $this->getMockBuilder('UserListener')
                         ->setMethods(array('onUserEmailChangedEvent'))
                         ->getMock();
        $listener->expects($this->once())
                     ->method('onUserEmailChangedEvent');

        /** @var EventDispatcherInterface $dispatcher */
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

        /** @var EventDispatcherInterface $dispatcher */
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
        $charge = new Charge();
        $user->addCharge($charge);
        $charge->setAmount(100);
        static::$dm->persist($charge);
        static::$dm->flush();

        $exception = false;
        try {
            static::$dm->remove($user);
            static::$dm->flush();
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertFalse($exception);
        $this->assertUserDoesNotExist(self::$container, $user);

        /** @var DocumentManager $dm */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Charge::class);
        /** @var Charge $updatedCharge */
        $updatedCharge = $repo->find($charge->getId());
        $this->assertNotNull($updatedCharge);
        if ($updatedCharge) {
            $this->assertEquals(100, $updatedCharge->getAmount());
            $this->assertNull($updatedCharge->getUser());
        }
    }
}
