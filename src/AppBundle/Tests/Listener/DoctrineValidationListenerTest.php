<?php

namespace AppBundle\Tests\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineValidationListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use AppBundle\Event\ObjectEvent;
use AppBundle\Document\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-nonet
 */
class DoctrineValidationListenerTest extends WebTestCase
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
    }

    public function tearDown()
    {
    }

    public function testPreUpdate()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('preupdate', $this));
        static::$dm->persist($user);

        $listener = $this->createObjectEventListener($user, $this->once(), ObjectEvent::EVENT_VALIDATE);

        $changeSet = [
            'confirmationToken' => ['123', null],
            'passwordRequestedAt' => [\DateTime::createFromFormat('U', time()), null]
        ];
        $events = new PreUpdateEventArgs($user, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPrePersist()
    {
        $user = new User();
        $user->setEmail(static::generateEmail('prepersist', $this));
        
        $listener = $this->createObjectEventListener($user, $this->once(), ObjectEvent::EVENT_VALIDATE);
    
        $events = new LifecycleEventArgs($user, self::$dm);
        $listener->prePersist($events);
    }

    private function createObjectEventListener($object, $count, $eventType)
    {
        $event = new ObjectEvent($object);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
                         ->setMethods(array('dispatch'))
                         ->getMock();
        $dispatcher->expects($count)
                     ->method('dispatch')
                     ->with($eventType, $event);

        $listener = new DoctrineValidationListener($dispatcher);

        return $listener;
    }
}
