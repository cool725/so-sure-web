<?php

namespace AppBundle\Tests\Listener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\InvitationListener;
use AppBundle\Listener\DoctrineInvitationListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\InvitationEvent;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-net
 */
class InvitationListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $userRepo;
    protected static $invitationRepo;
    protected static $userManager;
    protected static $testUser;
    protected static $testUser2;
    protected static $testUser3;
    protected static $testUser4;

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
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$invitationRepo = self::$dm->getRepository(Invitation::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$testUser = self::createUser(
            self::$userManager,
            'foo@invitation.foo.com',
            'foo'
        );
        self::$testUser2 = self::createUser(
            self::$userManager,
            'bar@invitation.foo.com',
            'bar'
        );
        self::$testUser3 = self::createUser(
            self::$userManager,
            'foobar@invitation.foo.com',
            'bar'
        );
        self::$testUser4 = self::createUser(
            self::$userManager,
            'barfoo@invitation.foo.com',
            'bar'
        );
    }

    public function tearDown()
    {
    }

    public function testEmailInvitationInvitee()
    {
        $invitation = new EmailInvitation();
        $invitation->setInviter(self::$testUser);
        $invitation->setEmail(self::$testUser2->getEmail());
        self::$dm->persist($invitation);

        $event = new InvitationEvent($invitation);

        $listener = new InvitationListener(self::$dm);
        $listener->onInvitationEvent($event);

        // Refresh invitation
        $this->assertTrue($invitation->getInvitee() !== null);
        $this->assertTrue($invitation->getInvitee()->getId() == self::$testUser2->getId());
    }

    public function testSmsInvitationInvitee()
    {
        $mobile = static::generateRandomMobile();
        self::$testUser2->setMobileNumber($mobile);
        self::$dm->flush();

        $invitation = new SmsInvitation();
        $invitation->setInviter(self::$testUser);
        $invitation->setMobile($mobile);
        self::$dm->persist($invitation);

        $event = new InvitationEvent($invitation);

        $listener = new InvitationListener(self::$dm);
        $listener->onInvitationEvent($event);

        // Refresh invitation
        $this->assertTrue($invitation->getInvitee() !== null);
        $this->assertTrue($invitation->getInvitee()->getId() == self::$testUser2->getId());
    }

    public function testSmsPreventSameUser()
    {
        $mobile = static::generateRandomMobile();
        self::$testUser3->setMobileNumber($mobile);
        self::$dm->flush();

        $invitation = new SmsInvitation();
        $invitation->setInviter(self::$testUser3);
        $invitation->setMobile($mobile);
        self::$dm->persist($invitation);

        $event = new InvitationEvent($invitation);

        $listener = new InvitationListener(self::$dm);
        $listener->onInvitationEvent($event);

        // Refresh invitation
        $this->assertTrue($invitation->getInvitee() == null);
    }

    public function testEmailPreventSameUser()
    {
        $invitation = new EmailInvitation();
        $invitation->setInviter(self::$testUser4);
        $invitation->setEmail('barfoo@invitation.foo.com');
        self::$dm->persist($invitation);

        $event = new InvitationEvent($invitation);

        $listener = new InvitationListener(self::$dm);
        $listener->onInvitationEvent($event);

        // Refresh invitation
        $this->assertTrue($invitation->getInvitee() == null);
    }

    public function testEmailInvitationInviteeKernelEvents()
    {
        $invitation = new EmailInvitation();
        $invitation->setInviter(self::$testUser);
        $invitation->setEmail(self::$testUser2->getEmail());
        self::$dm->persist($invitation);
        self::$dm->flush();

        // Refresh invitation
        $invitation = self::$invitationRepo->find($invitation->getId());
        $this->assertTrue($invitation->getInvitee() !== null);
        $this->assertTrue($invitation->getInvitee()->getId() == self::$testUser2->getId());
    }
}
