<?php

namespace AppBundle\Tests\Listener;

use Doctrine\Common\Annotations\Reader;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\DoctrineInvitationListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\InvitationEvent;
use AppBundle\Document\Invitation\EmailInvitation;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-nonet
 */
class DoctrineInvitationListenerTest extends WebTestCase
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
        self::$testUser = self::createUser(
            self::$userManager,
            'foo@doctrine.invitation.foo.com',
            'foo'
        );
    }

    public function tearDown()
    {
    }

    public function testPostUpdate()
    {
        $invitation = new EmailInvitation();
        $listener = $this->createListener($invitation, $this->once());
    
        $events = new LifecycleEventArgs($invitation, self::$dm);
        $listener->postUpdate($events);
    }

    public function testPostPersist()
    {
        $invitation = new EmailInvitation();
        $listener = $this->createListener($invitation, $this->once());
    
        $events = new LifecycleEventArgs($invitation, self::$dm);
        $listener->postPersist($events);
    }

    public function testInviteePostPersist()
    {
        $invitation = new EmailInvitation();
        $invitation->setInvitee(self::$testUser);
        $listener = $this->createListener($invitation, $this->never());
    
        $events = new LifecycleEventArgs($invitation, self::$dm);
        $listener->postPersist($events);
    }

    private function createListener($invitation, $count)
    {
        $event = new InvitationEvent($invitation);

        $dispatcher = $this->getMockBuilder('EventDispatcherInterface')
                         ->setMethods(array('dispatch'))
                         ->getMock();
        $dispatcher->expects($count)
                     ->method('dispatch')
                     ->with(InvitationEvent::EVENT_UPDATED, $event);

        $events = new LifecycleEventArgs($invitation, self::$dm);
        $listener = new DoctrineInvitationListener($dispatcher);

        return $listener;
    }
}
