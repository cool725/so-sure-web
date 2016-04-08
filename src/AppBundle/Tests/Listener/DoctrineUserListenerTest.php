<?php

namespace AppBundle\Tests\Listener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\UserEvent;
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

    public function testPostUpdate()
    {
        $user = new User();
        $user->setEmail('dul1@listener.so-sure.com');
        $listener = $this->createListener($user, $this->once());
    
        $events = new LifecycleEventArgs($user, self::$dm);
        $listener->postUpdate($events);
    }

    public function testPostPersist()
    {
        $user = new User();
        $user->setEmail('dul2@listener.so-sure.com');
        $listener = $this->createListener($user, $this->once());
    
        $events = new LifecycleEventArgs($user, self::$dm);
        $listener->postPersist($events);
    }

    private function createListener($user, $count)
    {
        $event = new UserEvent($user);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
                         ->setMethods(array('dispatch'))
                         ->getMock();
        $dispatcher->expects($count)
                     ->method('dispatch')
                     ->with(UserEvent::EVENT_UPDATED, $event);

        $events = new LifecycleEventArgs($user, self::$dm);
        $listener = new DoctrineUserListener($dispatcher);

        return $listener;
    }
}
