<?php

namespace AppBundle\Tests\Listener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use AppBundle\Event\UserEvent;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Document\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-nonet
 */
class DoctrineUserListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $userManager;
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
    }

    public function tearDown()
    {
    }

    public function testPreUpdate()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('pre', $this));
        static::$dm->persist($user);
        $listener = new DoctrineUserListener(null);

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

        $listener = new DoctrineUserListener($dispatcher);

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

        $listener = new DoctrineUserListener($dispatcher);

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
        $user->setEmail(strtolower(static::generateEmail('testUpdateUserEmailChangedButSame', $this)));
        static::$dm->flush();
    }
}
