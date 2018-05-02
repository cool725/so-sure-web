<?php

namespace AppBundle\Tests\Listener;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineClaimListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use AppBundle\Event\ClaimEvent;
use AppBundle\Document\Claim;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-nonet
 */
class DoctrineClaimListenerTest extends WebTestCase
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

    public function testPreUpdateOther()
    {
        $claim = new Claim();
        static::$dm->persist($claim);
        $listener = $this->createClaimEventListener($claim, $this->never(), []);

        $changeSet = ['type' => [null, 'foo']];
        $events = new PreUpdateEventArgs($claim, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateApproved()
    {
        $claim = new Claim();
        static::$dm->persist($claim);
        $listener = $this->createClaimEventListener($claim, $this->once(), [ClaimEvent::EVENT_APPROVED]);

        $changeSet = ['status' => [null, Claim::STATUS_APPROVED]];
        $events = new PreUpdateEventArgs($claim, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPreUpdateSettled()
    {
        $claim = new Claim();
        static::$dm->persist($claim);
        $listener = $this->createClaimEventListener($claim, $this->once(), [ClaimEvent::EVENT_SETTLED]);

        $changeSet = ['status' => [null, Claim::STATUS_SETTLED]];
        $events = new PreUpdateEventArgs($claim, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPostPersistCreated()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $listener = $this->createClaimEventListener($claim, $this->once(), [ClaimEvent::EVENT_CREATED]);
    
        $events = new LifecycleEventArgs($claim, self::$dm);
        $listener->postPersist($events);
    }

    public function testPostPersistApproved()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $listener = $this->createClaimEventListener($claim, $this->any(), [
            ClaimEvent::EVENT_CREATED,
            ClaimEvent::EVENT_APPROVED
        ]);
    
        $events = new LifecycleEventArgs($claim, self::$dm);
        $listener->postPersist($events);
    }

    public function testPostPersistSettled()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $listener = $this->createClaimEventListener($claim, $this->any(), [
            ClaimEvent::EVENT_CREATED,
            ClaimEvent::EVENT_SETTLED
        ]);
    
        $events = new LifecycleEventArgs($claim, self::$dm);
        $listener->postPersist($events);
    }

    private function createClaimEventListener(Claim $claim, $count, $eventTypes)
    {
        $event = new ClaimEvent($claim);
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

        $listener = new DoctrineClaimListener($dispatcher);

        return $listener;
    }
}
