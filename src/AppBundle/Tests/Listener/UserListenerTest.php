<?php

namespace AppBundle\Tests\Listener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Listener\UserListener;
use AppBundle\Listener\DoctrineUserListener;
use Doctrine\ODM\MongoDB\Event\LifecycleEventArgs;
use AppBundle\Event\UserEvent;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\User;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @group functional-net
 */
class UserListenerTest extends WebTestCase
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
            'foo@user-invitation.foo.com',
            'foo'
        );
        self::$testUser2 = self::createUser(
            self::$userManager,
            'bar@user-invitation.foo.com',
            'bar'
        );
    }

    public function tearDown()
    {
    }

    public function testUserWithEmailInvitation()
    {
        $invitation = new EmailInvitation();
        $invitation->setInviter(self::$testUser);
        $invitation->setEmail('email@user-invitation.foo.com');
        self::$dm->persist($invitation);
        self::$dm->flush();

        $user = new User();
        $user->setEmail('email@user-invitation.foo.com');
        
        $event = new UserEvent($user);

        $listener = new UserListener(self::$dm);
        $listener->onUserEvent($event);

        $this->assertTrue(count($user->getReceivedInvitations()) > 0);
    }

    public function testUserWithMobileInvitation()
    {
        $invitation = new SmsInvitation();
        $invitation->setInviter(self::$testUser2);
        $invitation->setMobile('12123');
        self::$dm->persist($invitation);
        self::$dm->flush();

        $user = new User();
        $user->setMobileNumber('12123');

        $event = new UserEvent($user);

        $listener = new UserListener(self::$dm);
        $listener->onUserEvent($event);

        $this->assertTrue(count($user->getReceivedInvitations()) > 0);
    }
}
