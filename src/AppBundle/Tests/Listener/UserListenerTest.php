<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Document\Lead;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
 *
 * \\AppBundle\\Tests\\Listener\\UserListenerTest
 */
class UserListenerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    protected static $leadRepo;
    protected static $invitationRepo;
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
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$leadRepo = self::$dm->getRepository(Lead::class);
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

        $listener = $this->getUserListener();
        $listener->onUserUpdatedEvent($event);

        $user = self::$userRepo->find($user->getId());
        $this->assertTrue($user->hasReceivedInvitations());
    }

    public function testUserWithLead()
    {
        $email = self::generateEmail('testUserWithLead', $this);

        $lead = new Lead();
        $lead->setEmail($email);
        $lead->setSource(Lead::LEAD_SOURCE_SCODE);
        $lead->setSourceDetails('foo');
        $lead = new Lead();
        self::$dm->persist($lead);
        self::$dm->flush();

        $user = new User();
        // set both - email canonical will get set on flush, but as we're not flushing...
        $user->setEmail($email);
        $user->setEmailCanonical(mb_strtolower($email));
        self::$dm->persist($user);

        $event = new UserEvent($user);

        $listener = $this->getUserListener();
        $listener->onUserUpdatedEvent($event);

        /** @var User $user */
        $user = self::$userRepo->find($user->getId());
        $this->assertEquals($lead->getSource(), $user->getLeadSource());
        $this->assertEquals($lead->getSourceDetails(), $user->getLeadSourceDetails());
    }

    public function testUserWithExitingDetailsAndLead()
    {
        $email = self::generateEmail('testUserWithExitingDetailsAndLead', $this);

        $lead = new Lead();
        $lead->setEmail($email);
        $lead->setSource(Lead::LEAD_SOURCE_SCODE);
        $lead->setSourceDetails('foo');
        $lead = new Lead();
        self::$dm->persist($lead);
        self::$dm->flush();

        $user = new User();
        // set both - email canonical will get set on flush, but as we're not flushing...
        $user->setEmail($email);
        $user->setEmailCanonical(mb_strtolower($email));
        $user->setLeadSource(Lead::LEAD_SOURCE_AFFILIATE);
        $user->setLeadSourceDetails('bar');
        self::$dm->persist($user);

        $event = new UserEvent($user);

        $listener = $this->getUserListener();
        $listener->onUserUpdatedEvent($event);

        /** @var User $user */
        $user = self::$userRepo->find($user->getId());
        $this->assertNotEquals($lead->getSource(), $user->getLeadSource());
        $this->assertNotEquals($lead->getSourceDetails(), $user->getLeadSourceDetails());
        $this->assertEquals(Lead::LEAD_SOURCE_AFFILIATE, $user->getLeadSource());
        $this->assertEquals('bar', $user->getLeadSourceDetails());
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

        $mobile = static::generateRandomMobile();
        $invitation = new SmsInvitation();
        $invitation->setInviter($testUserMobile);
        $invitation->setMobile($mobile);
        self::$dm->persist($invitation);
        self::$dm->flush();

        $user = new User();
        $user->setMobileNumber($mobile);
        $user->setEmail(self::generateEmail('mobile-match', $this));
        self::$dm->persist($user);

        $event = new UserEvent($user);

        $listener = $this->getUserListener();
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

    public function testUserChangePassword()
    {
        $user = static::createUser(
            self::$userManager,
            self::generateEmail('testUserChangePassword', $this),
            'foo'
        );
        static::$dm->flush();

        $user->setPlainPassword('foooBarr1!');
        static::$userManager->updatePassword($user);
        static::$dm->flush();

        $user->setPlainPassword('foooBarr2!');
        static::$userManager->updatePassword($user);
        static::$dm->flush();

        $user->setPlainPassword('foooBarr1!');
        $event = new UserEvent($user);

        $listener = $this->getUserListener();
        $exception = false;
        try {
            $listener->onUserPasswordChangedEvent($event);
        } catch (\Exception $e) {
            $exception = true;
        }
        $this->assertTrue($exception);

        $user->setPlainPassword('foooBarr3!');
        static::$userManager->updatePassword($user);
        static::$dm->flush();
        $listener->onUserPasswordChangedEvent($event);
    }

    private function getUserListener()
    {
        $listener = new UserListener(
            self::$dm,
            self::$container->get('logger'),
            self::$container->get('app.mailer'),
            self::$container->get('snc_redis.default'),
            self::$container->get('app.user')
        );

        return $listener;
    }
}
