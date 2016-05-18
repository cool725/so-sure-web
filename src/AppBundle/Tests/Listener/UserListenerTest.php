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
    }

    public function tearDown()
    {
    }

    public function testUserWithEmailInvitation()
    {
        $testUser = static::createUser(
            self::$userManager,
            self::generateEmail('foo', $this),
            'foo'
        );

        $invitation = new EmailInvitation();
        $invitation->setInviter($testUser);
        $invitation->setEmail(self::generateEmail('invite1', $this));
        self::$dm->persist($invitation);
        self::$dm->flush();

        $user = new User();
        // set both - email canonical will get set on flush, but as we're not flushing...
        $user->setEmail(self::generateEmail('invite1', $this));
        $user->setEmailCanonical(self::generateEmail('invite1', $this));
        self::$dm->persist($user);

        $event = new UserEvent($user);

        $listener = new UserListener(self::$dm);
        $listener->onUserUpdatedEvent($event);

        $user = self::$userRepo->find($user->getId());
        $this->assertTrue($user->hasReceivedInvitations());
    }

    public function testUserWithEmailInvitationActual()
    {
        $testUser = static::createUser(
            self::$userManager,
            self::generateEmail('foo-inviter', $this),
            'foo'
        );

        $invitation = new EmailInvitation();
        $invitation->setInviter($testUser);
        $invitation->setEmail(self::generateEmail('invite2', $this));
        self::$dm->persist($invitation);
        self::$dm->flush();

        $newUser = static::createUser(
            self::$userManager,
            self::generateEmail('invite2', $this),
            'foo'
        );

        $newUser = self::$userRepo->find($newUser->getId());
        $this->assertTrue($newUser->hasReceivedInvitations());
    }

    public function testUserWithMobileInvitation()
    {
        $testUserMobile = static::createUser(
            self::$userManager,
            self::generateEmail('mobile', $this),
            'mobile'
        );

        $invitation = new SmsInvitation();
        $invitation->setInviter($testUserMobile);
        $invitation->setMobile('13123');
        self::$dm->persist($invitation);
        self::$dm->flush();

        $user = new User();
        $user->setMobileNumber('13123');
        self::$dm->persist($user);

        $event = new UserEvent($user);

        $listener = new UserListener(self::$dm);
        $listener->onUserUpdatedEvent($event);

        $user = self::$userRepo->find($user->getId());
        $count = 0;
        foreach ($user->getReceivedInvitations() as $invitation) {
            $count = $count + 1;
            //\Doctrine\Common\Util\Debug::dump($invitation);
        }
        $user = self::$userRepo->find($user->getId());
        $this->assertTrue($user->hasReceivedInvitations());
    }

    public function testUserEmailVerified()
    {
        $user = static::createUser(
            self::$userManager,
            self::generateEmail('validate', $this),
            'validate'
        );

        $this->assertNull($user->getEmailVerified());
        $event = new UserEvent($user);

        $listener = new UserListener(self::$dm);
        $listener->onUserEmailVerifiedEvent($event);

        $updatedUser = self::$userRepo->find($user->getId());
        $this->assertTrue($updatedUser->getEmailVerified());
    }
}
