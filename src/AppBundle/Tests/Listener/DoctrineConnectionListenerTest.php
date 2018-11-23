<?php

namespace AppBundle\Tests\Listener;

use Doctrine\Common\Annotations\Reader;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineConnectionListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use AppBundle\Event\ConnectionEvent;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\Connection\StandardConnection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-nonet
 */
class DoctrineConnectionListenerTest extends WebTestCase
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
    }

    public function tearDown()
    {
    }

    public function testPreUpdateConnectionValueReduction()
    {
        $connection = new StandardConnection();
        static::$dm->persist($connection);
        $listener = $this->createConnectionEventListener($connection, $this->once(), [ConnectionEvent::EVENT_REDUCED]);

        $changeSet = ['value' => [10, 5.8]];
        $events = new PreUpdateEventArgs($connection, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateConnectionValueSame()
    {
        $connection = new StandardConnection();
        static::$dm->persist($connection);
        $listener = $this->createConnectionEventListener($connection, $this->never(), []);

        $changeSet = ['value' => [10, 10]];
        $events = new PreUpdateEventArgs($connection, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateConnectionPromoValueReduction()
    {
        $connection = new StandardConnection();
        static::$dm->persist($connection);
        $listener = $this->createConnectionEventListener($connection, $this->once(), [ConnectionEvent::EVENT_REDUCED]);

        $changeSet = ['promoValue' => [10, 5.8]];
        $events = new PreUpdateEventArgs($connection, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateConnectionPromoValueSame()
    {
        $connection = new StandardConnection();
        static::$dm->persist($connection);
        $listener = $this->createConnectionEventListener($connection, $this->never(), []);

        $changeSet = ['promoValue' => [10, 10]];
        $events = new PreUpdateEventArgs($connection, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    private function createConnectionEventListener(Connection $connection, $count, $eventTypes)
    {
        $event = new ConnectionEvent($connection);
        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
                         ->setMethods(array('dispatch'))
                         ->getMock();
        if ($count != $this->never()) {
            $loop = 0;
            foreach ($eventTypes as $eventType) {
                $dispatcher->expects($this->at($loop))
                             ->method('dispatch')
                             ->with($eventType, $event);
                $loop++;
            }
        } else {
            $dispatcher->expects($count)
                         ->method('dispatch');
        }

        $listener = new DoctrineConnectionListener($dispatcher);
        /** @var Reader $reader */
        $reader = static::$container->get('annotations.reader');
        $listener->setReader($reader);

        return $listener;
    }
}
