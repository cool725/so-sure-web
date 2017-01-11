<?php

namespace AppBundle\Tests\Listener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineLeadListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use Doctrine\ODM\MongoDB\Event\PreUpdateEventArgs;
use AppBundle\Event\LeadEvent;
use AppBundle\Document\Lead;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-nonet
 */
class DoctrineLeadListenerTest extends WebTestCase
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
        $lead = new Lead();
        $lead->setEmail(static::generateEmail('pre', $this));
        static::$dm->persist($lead);
        $listener = new DoctrineLeadListener(null);

        $changeSet = ['confirmationToken' => ['123', null], 'passwordRequestedAt' => [new \DateTime(), null]];
        $events = new PreUpdateEventArgs($lead, self::$dm, $changeSet);
        $listener->preUpdate($events);
    }

    public function testPostUpdate()
    {
        $lead = new Lead();
        $lead->setEmail('dul1@lead-listener.so-sure.com');
        $listener = $this->createLeadEventListener($lead, $this->once(), LeadEvent::EVENT_UPDATED);
    
        $events = new LifecycleEventArgs($lead, self::$dm);
        $listener->postUpdate($events);
    }

    public function testPostPersist()
    {
        $lead = new Lead();
        $lead->setEmail('dul2@lead-listener.so-sure.com');
        $listener = $this->createLeadEventListener($lead, $this->once(), LeadEvent::EVENT_UPDATED);
    
        $events = new LifecycleEventArgs($lead, self::$dm);
        $listener->postPersist($events);
    }

    private function createLeadEventListener($lead, $count, $eventType)
    {
        $event = new LeadEvent($lead);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
                         ->setMethods(array('dispatch'))
                         ->getMock();
        $dispatcher->expects($count)
                     ->method('dispatch')
                     ->with($eventType, $event);

        $listener = new DoctrineLeadListener($dispatcher);

        return $listener;
    }
}
