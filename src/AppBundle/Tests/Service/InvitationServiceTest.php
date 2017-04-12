<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;

use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Address;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\SCodeInvitation;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;

use AppBundle\Service\InvitationService;
use AppBundle\Service\MailerService;

use AppBundle\Event\InvitationEvent;

use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\FullPotException;
use AppBundle\Exception\ClaimException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\ConnectedInvitationException;

/**
 * @group functional-nonet
 */
class InvitationServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $gocardless;
    protected static $dm;
    protected static $userRepo;
    protected static $userManager;
    protected static $invitationService;
    protected static $phone;
    protected static $phone2;
    protected static $policyService;

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
        self::$userManager = self::$container->get('fos_user.user_manager');
        $transport = new \Swift_Transport_NullTransport(new \Swift_Events_SimpleEventDispatcher);
        $mailer = new MailerService(
            \Swift_Mailer::newInstance($transport),
            $transport,
            self::$container->get('templating'),
            self::$container->get('api.router'),
            'foo@foo.com',
            'bar'
        );
        self::$invitationService = self::$container->get('app.invitation');
        self::$invitationService->setMailer($mailer);
        self::$invitationService->setDebug(true);

        self::$policyService = self::$container->get('app.policy');

        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phone2 = $phoneRepo->findOneBy(['devices' => 'iPhone8,1', 'memory' => 64]);
    }

    public function setUp()
    {
        // reset environment
        self::$invitationService->setEnvironment('test');
        self::$policyService->setEnvironment('test');
    }

    public function tearDown()
    {
    }

    /**
     * @expectedException AppBundle\Exception\DuplicateInvitationException
     */
    public function testDuplicateEmail()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testDuplicateEmail-user', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('testDuplicateEmail-invite', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);
        static::$dm->flush();

        self::$invitationService->inviteByEmail($policy, static::generateEmail('testDuplicateEmail-invite', $this));
    }

    public function testDuplicateEmailReInvites()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testDuplicateEmailReInvites-user', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('testDuplicateEmailReInvites-invite', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);


        // allow reinvites
        $before = new \DateTime();
        $before = $before->sub(new \DateInterval('PT1S'));
        $invitation->setNextReinvited($before);
        static::$dm->flush();

        self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('testDuplicateEmailReInvites-invite', $this)
        );
    }

    /**
     * @expectedException AppBundle\Exception\OptOutException
     */
    public function testSoSureEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSoSureEmailInvitation-user', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, true, false);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        self::$invitationService->setEnvironment('prod');
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            'foo@so-sure.com'
        );
    }

    /**
     * @expectedException AppBundle\Exception\FullPotException
     */
    public function testProdSoSurePolicyOtherEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            'testSoSurePolicyOtherEmailInvitation-user@so-sure.com',
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, true, false);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        self::$invitationService->setEnvironment('prod');
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            'foo@so-sure.net'
        );
    }

    public function testProdSoSurePolicyEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            'testSoSurePolicyEmailInvitation-user@so-sure.com',
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, true, false);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy);
        static::$policyService->setEnvironment('test');

        $this->expectInvitationEvent(InvitationEvent::EVENT_INVITED, 'onInvitationInvitedEvent');

        self::$invitationService->setEnvironment('prod');
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            'foo@so-sure.com'
        );
        self::$invitationService->setEnvironment('test');
        $this->assertTrue($invitation instanceof EmailInvitation);
    }

    public function testEmailInvitationExistingUser()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testEmailInvitationExistingUser', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, true, false);
        static::$policyService->create($policy);

        $invitee = static::createUser(
            static::$userManager,
            static::generateEmail('testEmailInvitationExistingUser-invitee', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($invitee, static::$dm, static::$phone, null, true, false);
        static::$policyService->create($policyInvitee);

        $this->expectInvitationEvent(InvitationEvent::EVENT_RECEIVED, 'onInvitationReceivedEvent');

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('testEmailInvitationExistingUser-invitee', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);
    }

    /**
     * @expectedException AppBundle\Exception\FullPotException
     */
    public function testAcceptSoSureEmail()
    {
        $inviter = static::createUser(
            static::$userManager,
            'testAcceptSoSureEmail-inviter@so-sure.com',
            'bar'
        );
        $inviterPolicy = static::initPolicy($inviter, static::$dm, static::$phone, null, true, false);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($inviterPolicy);
        static::$policyService->setEnvironment('test');

        $invitee = static::createUser(
            static::$userManager,
            'testAcceptSoSureEmail-invitee@so-sure.com',
            'bar'
        );
        $inviteePolicy = static::initPolicy($invitee, static::$dm, static::$phone, null, true, false);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($inviteePolicy);
        static::$policyService->setEnvironment('test');

        self::$invitationService->setEnvironment('prod');
        $invitation = self::$invitationService->inviteByEmail(
            $inviterPolicy,
            'testAcceptSoSureEmail-invitee@so-sure.com'
        );
        self::$invitationService->setEnvironment('test');

        $invitee->setEmail('testAcceptSoSureEmail-invitee@so-sure.net');
        static::$dm->flush();

        self::$invitationService->setEnvironment('prod');
        self::$invitationService->accept($invitation, $inviteePolicy);
    }

    /**
     * @expectedException AppBundle\Exception\ProcessedException
     */
    public function testConcurrentAccept()
    {
        $inviter = static::createUser(
            static::$userManager,
            static::generateEmail('testConcurrentAccept-user', $this),
            'bar'
        );
        $inviterPolicy = static::initPolicy($inviter, static::$dm, static::$phone, null, true, false);
        static::$policyService->create($inviterPolicy);

        $invitee = static::createUser(
            static::$userManager,
            static::generateEmail('testConcurrentAccept-invitee', $this),
            'bar'
        );
        $inviteePolicy = static::initPolicy($invitee, static::$dm, static::$phone, null, true, false);
        static::$policyService->create($inviteePolicy);

        $invitation = self::$invitationService->inviteByEmail(
            $inviterPolicy,
            static::generateEmail('testConcurrentAccept-invitee', $this)
        );

        self::$invitationService->accept($invitation, $inviteePolicy);
        $invitation->setAccepted(null);
        $invitation->setEmail(static::generateEmail('testConcurrentAccept-invitee2', $this));
        static::$dm->flush();
        self::$invitationService->accept($invitation, $inviteePolicy);
    }

    /**
     * @expectedException AppBundle\Exception\DuplicateInvitationException
     */
    public function testDuplicateEmailInvitationRejected()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user1', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite1', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);
        $invitation->setRejected(new \DateTime());
        static::$dm->flush();

        self::$invitationService->inviteByEmail($policy, static::generateEmail('invite1', $this));
    }

    public function testDuplicateEmailInvitationCancelled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-cancelled', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite-dup1', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);
        $invitation->setCancelled(new \DateTime());
        static::$dm->flush();

        $invite = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite-dup1', $this));
        $this->assertNull($invite->getCancelled());
    }

    /**
     * @expectedException AppBundle\Exception\ConnectedInvitationException
     */
    public function testConnectedEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('connected-user', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-connected', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-connected', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        self::$invitationService->accept($invitation, $policyInvitee, new \DateTime('2016-05-01'));
        $invitation = self::$invitationService->inviteByEmail(
            $policyInvitee,
            static::generateEmail('connected-user', $this)
        );
    }

    /**
     * @expectedException AppBundle\Exception\OptOutException
     */
    public function testOptOutCatIntivationsEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user2', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $optOut = new EmailOptOut();
        $optOut->setEmail(static::generateEmail('invite2', $this));
        $optOut->setCategory(EmailOptOut::OPTOUT_CAT_INVITATIONS);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite2', $this));
    }

    /**
     * @expectedException AppBundle\Exception\OptOutException
     */
    public function testOptOutCatAllEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user3', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $optOut = new EmailOptOut();
        $optOut->setEmail(static::generateEmail('invite3', $this));
        $optOut->setCategory(EmailOptOut::OPTOUT_CAT_ALL);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite3', $this));
    }

    public function testNoOptOutEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user4', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite4', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);
    }

    public function testSmsInvitationInvitee()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('sms-invitee1', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('sms-invitee2', $this),
            'bar'
        );
        $mobile = static::generateRandomMobile();
        $userInvitee->setMobileNumber($mobile);
        static::$dm->flush();
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySms($policy, $mobile);
        $this->assertTrue($invitation instanceof SmsInvitation);
        $this->assertNotNull($invitation->getInvitee());
        $this->assertEquals($userInvitee->getId(), $invitation->getInvitee()->getId());
    }

    /**
     * @expectedException AppBundle\Exception\DuplicateInvitationException
     */
    public function testDuplicateSmsInvitationRejected()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser1', $this),
            'bar'
        );
        $mobile = static::generateRandomMobile();
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $invitation = self::$invitationService->inviteBySms($policy, $mobile);
        $this->assertTrue($invitation instanceof SmsInvitation);
        $invitation->setRejected(new \DateTime());
        static::$dm->flush();

        self::$invitationService->inviteBySms($policy, self::transformMobile($mobile));
    }
    
    public function testDuplicateSmsInvitationCancelled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser-dup', $this),
            'bar'
        );
        $mobile = static::generateRandomMobile();
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $invitation = self::$invitationService->inviteBySms($policy, $mobile);
        $this->assertTrue($invitation instanceof SmsInvitation);
        $invitation->setCancelled(new \DateTime());
        static::$dm->flush();

        $invite = self::$invitationService->inviteBySms($policy, self::transformMobile($mobile));
        $this->assertNull($invite->getCancelled());
    }

    /**
     * @expectedException AppBundle\Exception\ConnectedInvitationException
     */
    public function testConnectedMobileInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('connected-mobile-user', $this),
            'bar'
        );
        $user->setMobileNumber(static::generateRandomMobile());
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-mobile-connected', $this),
            'bar'
        );
        $userInvitee->setMobileNumber(static::generateRandomMobile());
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySms($policy, $userInvitee->getMobileNumber());
        $this->assertTrue($invitation instanceof SmsInvitation);

        self::$invitationService->accept($invitation, $policyInvitee, new \DateTime('2016-05-01'));
        self::$invitationService->inviteBySms($policyInvitee, $user->getMobileNumber());
    }

    public function testOptOutCatIntivationsSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser2', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $mobile = static::generateRandomMobile();
        $optOut = new SmsOptOut();
        $optOut->setMobile($mobile);
        $optOut->setCategory(SmsOptOut::OPTOUT_CAT_INVITATIONS);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->inviteBySms($policy, $mobile);
        $this->assertNull($invitation);
    }

    public function testOptOutCatAllSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser3', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $mobile = static::generateRandomMobile();
        $optOut = new SmsOptOut();
        $optOut->setMobile($mobile);
        $optOut->setCategory(EmailOptOut::OPTOUT_CAT_ALL);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->inviteBySms($policy, $mobile);
        $this->assertNull($invitation);
    }

    public function testNoOptOutSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser4', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySms($policy, static::generateRandomMobile());
        $this->assertTrue($invitation instanceof SmsInvitation);
    }

    /**
     * @expectedException \Exception
     */
    public function testSmsInvitationReinviteOptOut()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSmsInvitationReinviteOptOut-user', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('testSmsInvitationReinviteOptOut-invite', $this),
            'bar'
        );
        $mobile = static::generateRandomMobile();
        $userInvitee->setMobileNumber($mobile);
        $invitation = self::$invitationService->inviteBySms($policy, $mobile);
        $this->assertTrue($invitation instanceof SmsInvitation);

        $optOut = new SmsOptOut();
        $optOut->setMobile($mobile);
        $optOut->setCategory(SmsOptOut::OPTOUT_CAT_INVITATIONS);
        static::$dm->persist($optOut);
        static::$dm->flush();

        // allow reinvitation
        $invitation->setNextReinvited(new \DateTime('2016-01-01'));

        // sms reinvites are currently not allowed - exception thrown
        self::$invitationService->reinvite($invitation);

        $this->assertEquals(SmsInvitation::STATUS_SKIPPED, $invitation->getStatus());
    }

    private function removeAllEmailInvitations()
    {
        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        foreach ($emailInvitationRepo->findAll() as $invite) {
            static::$dm->remove($invite);
        }
        static::$dm->flush();
    }

    /**
     * @expectedException AppBundle\Exception\RateLimitException
     */
    public function testEmailReinviteRateLimited()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-ratelimit', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-ratelimit', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-ratelimit', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        self::$invitationService->reinvite($invitation);
    }

    public function testEmailInvitationAccept()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user8', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite8', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite8', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);

        $this->expectInvitationEvent(InvitationEvent::EVENT_ACCEPTED, 'onInvitationAcceptedEvent');

        self::$invitationService->accept($invitation, $policyInvitee);

        $this->assertTrue($invitation->isAccepted());
        $this->assertEquals(10, $policy->getPotValue());
        $this->assertEquals(10, $policyInvitee->getPotValue());
    }

    public function testEmailInvitationInvitee()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-invitee1', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('user-invitee2', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('user-invitee2', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);
        $this->assertNotNull($invitation->getInvitee());
        $this->assertEquals($userInvitee->getId(), $invitation->getInvitee()->getId());
    }

    public function testSCodeInvitationInvitee()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testSCodeInvitationInvitee-inviter', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('testSCodeInvitationInvitee-invitee', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySCode($policy, $policyInvitee->getStandardSCode()->getCode());
        $this->assertTrue($invitation instanceof SCodeInvitation);
        $this->assertNotNull($invitation->getInvitee());
        $this->assertEquals($userInvitee->getId(), $invitation->getInvitee()->getId());
    }

    /**
     * @expectedException AppBundle\Exception\SelfInviteException
     */
    public function testEmailInvitationSelf()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user9', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('user9', $this));
    }

    /**
     * @expectedException AppBundle\Exception\FullPotException
     */
    public function testEmailInvitationPotFilled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-maxpot', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $this->assertTrue($policy->isPolicy());
        $policy->setPotValue($policy->getMaxPot());

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite-maxpot', $this));
    }

    /**
     * @expectedException AppBundle\Exception\ClaimException
     */
    public function testEmailInvitationClaims()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-claims', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $this->assertTrue($policy->isPolicy());
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite-claims', $this));
    }

    /**
     * @expectedException AppBundle\Exception\SelfInviteException
     */
    public function testMobileInvitationSelf()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user10', $this),
            'bar'
        );
        $user->setMobileNumber('+447700900001');
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySms($policy, '07700900001');
    }

    public function testSCodeInvitation()
    {
        $inviterUser = static::createUser(
            static::$userManager,
            static::generateEmail('inviter-scode', $this),
            'bar'
        );
        static::$dm->persist($inviterUser);
        $inviterPolicy = static::initPolicy($inviterUser, static::$dm, static::$phone, null, false, true);

        $inviteeUser = static::createUser(
            static::$userManager,
            static::generateEmail('invitee-scode', $this),
            'bar'
        );
        static::$dm->persist($inviteeUser);
        $inviteePolicy = static::initPolicy($inviteeUser, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySCode(
            $inviteePolicy,
            $inviterPolicy->getStandardSCode()->getCode()
        );
        $this->assertTrue($invitation instanceof EmailInvitation);
    }

    public function testSCodeInvitationSetsLeadSource()
    {
        $inviterUser = static::createUser(
            static::$userManager,
            static::generateEmail('testSCodeInvitationSetsLeadSource-inviter', $this),
            'bar'
        );
        $inviterPolicy = static::initPolicy($inviterUser, static::$dm, static::$phone, null, false, true);

        $inviteeUser = static::createUser(
            static::$userManager,
            static::generateEmail('testSCodeInvitationSetsLeadSource-invitee', $this),
            'bar'
        );
        $inviteePolicy = static::initPolicy($inviteeUser, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySCode(
            $inviteePolicy,
            $inviterPolicy->getStandardSCode()->getCode()
        );
        $this->assertTrue($invitation instanceof EmailInvitation);
        $this->assertEquals(Lead::LEAD_SOURCE_SCODE, $inviteePolicy->getLeadSource());
    }

    public function testSCodeInvitationWithLeadSourceDoesNotChangeLeadSource()
    {
        $inviterUser = static::createUser(
            static::$userManager,
            static::generateEmail('testSCodeInvitationWithLeadSourceDoesNotChangeLeadSource-inviter', $this),
            'bar'
        );
        $inviterPolicy = static::initPolicy($inviterUser, static::$dm, static::$phone, null, false, true);

        $inviteeUser = static::createUser(
            static::$userManager,
            static::generateEmail('testSCodeInvitationWithLeadSourceDoesNotChangeLeadSource-invitee', $this),
            'bar'
        );
        $inviteePolicy = static::initPolicy($inviteeUser, static::$dm, static::$phone, null, false, true);
        $inviteePolicy->setLeadSource(Lead::LEAD_SOURCE_INVITATION);
        $this->assertEquals(Lead::LEAD_SOURCE_INVITATION, $inviteePolicy->getLeadSource());

        $invitation = self::$invitationService->inviteBySCode(
            $inviteePolicy,
            $inviterPolicy->getStandardSCode()->getCode()
        );
        $this->assertTrue($invitation instanceof EmailInvitation);
        $this->assertEquals(Lead::LEAD_SOURCE_INVITATION, $inviteePolicy->getLeadSource());
    }

    public function testOldSCodeInvitationDoesNotSetLeadSource()
    {
        $inviterUser = static::createUser(
            static::$userManager,
            static::generateEmail('testOldSCodeInvitationDoesNotSetLeadSource-inviter', $this),
            'bar'
        );
        $inviterPolicy = static::initPolicy($inviterUser, static::$dm, static::$phone, null, false, true);

        $inviteeUser = static::createUser(
            static::$userManager,
            static::generateEmail('testOldSCodeInvitationDoesNotSetLeadSource-invitee', $this),
            'bar'
        );
        $inviteePolicy = static::initPolicy($inviteeUser, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySCode(
            $inviteePolicy,
            $inviterPolicy->getStandardSCode()->getCode(),
            new \DateTime('2 days')
        );
        $this->assertTrue($invitation instanceof EmailInvitation);
        $this->assertNotEquals(Lead::LEAD_SOURCE_SCODE, $inviteePolicy->getLeadSource());
    }

    public function testFacebookInvitation()
    {
        $inviterUser = static::createUser(
            static::$userManager,
            static::generateEmail('testFacebookInvitation-inviter', $this),
            'bar'
        );
        $inviterPolicy = static::initPolicy($inviterUser, static::$dm, static::$phone, null, false, true);

        $inviteeUser = static::createUser(
            static::$userManager,
            static::generateEmail('testFacebookInvitation-invitee', $this),
            'bar'
        );
        $inviteeUser->setFacebookId(rand(1, 999999));
        static::$dm->persist($inviteeUser);
        static::$dm->flush();
        $inviteePolicy = static::initPolicy($inviteeUser, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByFacebookId(
            $inviterPolicy,
            $inviteeUser->getFacebookId()
        );
        $this->assertTrue($invitation instanceof EmailInvitation);
    }

    /**
     * @expectedException \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     */
    public function testFacebookInvitationNoFacebook()
    {
        $inviterUser = static::createUser(
            static::$userManager,
            static::generateEmail('testFacebookInvitationNoFacebook', $this),
            'bar'
        );
        $inviterPolicy = static::initPolicy($inviterUser, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByFacebookId(
            $inviterPolicy,
            -1
        );
    }

    /**
     * @expectedException AppBundle\Exception\FullPotException
     */
    public function testAcceptPotFilled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-accept-fullpot', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $this->assertTrue($policy->isPolicy());

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept-maxpot', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept-maxpot', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        $policy->setPotValue($policy->getMaxPot());

        self::$invitationService->accept($invitation, $policyInvitee);
    }

    /**
     * @expectedException AppBundle\Exception\ClaimException
     */
    public function testAcceptClaims()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-accept-claims', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $this->assertTrue($policy->isPolicy());

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept-claims', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept-claims', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        self::$invitationService->accept($invitation, $policyInvitee);
    }

    public function testAccept()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-accept', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, new \DateTime('2016-01-01'), true, false);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, new \DateTime('2016-01-01'));
        $this->assertTrue($policy->isPolicy());

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy(
            $userInvitee,
            static::$dm,
            static::$phone,
            new \DateTime('2016-04-01'),
            true,
            false
        );
        static::$policyService->create($policyInvitee, new \DateTime('2016-04-01'));

        self::$invitationService->setEnvironment('prod');
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        self::$invitationService->accept($invitation, $policyInvitee, new \DateTime('2016-05-01'));

        $repo = static::$dm->getRepository(Policy::class);
        $inviterPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($inviterPolicy->getConnections() as $connection) {
            if ($connection->getLinkedPolicy()->getId() == $policyInvitee->getId()) {
                $connectionFound = true;
                $this->assertEquals(2, $connection->getTotalValue());
                $this->assertEquals($invitation->getId(), $connection->getInvitation()->getId());
                $this->assertEquals($invitation->getCreated(), $connection->getInitialInvitationDate());
                $this->assertFalse($connection->getExcludeReporting());
            }
        }
        $this->assertTrue($connectionFound);

        $inviteePolicy = $repo->find($policyInvitee->getId());
        // user created before invitation, so shouldn't be set
        $this->assertNull($inviteePolicy->getUser()->getLeadSource());
        $connectionFound = false;
        foreach ($inviteePolicy->getConnections() as $connection) {
            if ($connection->getLinkedPolicy()->getId() == $inviterPolicy->getId()) {
                $connectionFound = true;
                $this->assertEquals(10, $connection->getTotalValue());
                $this->assertEquals($invitation->getId(), $connection->getInvitation()->getId());
                $this->assertEquals($invitation->getCreated(), $connection->getInitialInvitationDate());
                $this->assertFalse($connection->getExcludeReporting());
            }
        }
        $this->assertTrue($connectionFound);
    }

    public function testInviteLeadSource()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-inviter-lead', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, new \DateTime('2016-01-01'), false, true);
        $this->assertTrue($policy->isPolicy());

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept-lead', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        // need a slight delay between when invitation is created and new user
        sleep(1);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept-lead', $this),
            'bar'
        );

        $this->assertTrue($invitation->getCreated() < $userInvitee->getCreated(), sprintf(
            '%s <? %s',
            $invitation->getCreated()->format(\DateTime::ATOM),
            $userInvitee->getCreated()->format(\DateTime::ATOM)
        ));

        $repo = static::$dm->getRepository(User::class);
        $userInviteeUpdated = $repo->find($userInvitee->getId());
        $this->assertEquals('invitation', $userInviteeUpdated->getLeadSource());
    }

    public function testAcceptWithCancelled30days()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-accept-cancel', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, new \DateTime('2016-01-01'), false, true);
        $this->assertTrue($policy->isPolicy());

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept-cancel-before', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy(
            $userInvitee,
            static::$dm,
            static::$phone,
            new \DateTime('2016-02-01'),
            false,
            true
        );

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept-cancel-before', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        self::$invitationService->accept($invitation, $policyInvitee, new \DateTime('2016-02-01'));

        // Now Cancel policy
        self::$policyService->cancel(
            $policyInvitee,
            Policy::CANCELLED_ACTUAL_FRAUD,
            false,
            new \DateTime('2016-04-03')
        );

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept-cancel-after', $this),
            'bar'
        );
        $policyInviteeAfter = static::initPolicy(
            $userInvitee,
            static::$dm,
            static::$phone,
            new \DateTime('2016-04-10'),
            false,
            true
        );

        $invitationAfter = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept-cancel-after', $this)
        );
        $this->assertTrue($invitationAfter instanceof EmailInvitation);

        self::$invitationService->accept($invitationAfter, $policyInviteeAfter, new \DateTime('2016-04-10'));

        $repo = static::$dm->getRepository(Policy::class);
        $inviterPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($inviterPolicy->getConnections() as $connection) {
            if ($connection->getLinkedPolicy()->getId() == $policyInviteeAfter->getId()) {
                $connectionFound = true;
                $this->assertEquals(10, $connection->getTotalValue());
                // non prod acceptence without prefix
                $this->assertTrue($connection->getExcludeReporting());
            }
        }
        $this->assertTrue($connectionFound);
    }

    /**
     * @expectedException AppBundle\Exception\FullPotException
     */
    public function testEmailInvitationReinviteMaxPot()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-reinvite-maxpot', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-reinvite-maxpot', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-reinvite-maxpot', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        // allow reinvitation
        $invitation->setNextReinvited(new \DateTime('2016-01-01'));
        // but set pot value to maxpot
        $policy->setPotValue($policy->getMaxPot());

        self::$invitationService->reinvite($invitation);
    }

    /**
     * @expectedException AppBundle\Exception\ClaimException
     */
    public function testEmailInvitationReinviteClaim()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-reinvite-claim', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-reinvite-claim', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-reinvite-claim', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        // allow reinvitation
        $invitation->setNextReinvited(new \DateTime('2016-01-01'));
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        self::$invitationService->reinvite($invitation);
    }

    public function testAcceptConnectionReductionStandard()
    {
        $policy = $this->createAndLink(static::generateEmail('user-reduction', $this), new \DateTime('2016-01-01'));

        // gwp has now changed as figures are being added post
        $this->assertEquals(67.10, $policy->getMaxPot());
        //$this->assertEquals(66.72, $policy->getMaxPot());

        for ($i = 1; $i <= 6; $i++) {
            $this->createAndLink(
                static::generateEmail(sprintf('invite-accept-%d', $i), $this),
                new \DateTime('2016-02-01'),
                static::generateEmail('user-reduction', $this),
                $policy
            );
        }

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($checkPolicy->getConnections() as $connection) {
            $connectionFound = true;
            $this->assertEquals(10, $connection->getTotalValue());
        }
        $this->assertTrue($connectionFound);

        $this->createAndLink(
            static::generateEmail('invite-accept-0', $this),
            new \DateTime('2016-02-01'),
            static::generateEmail('user-reduction', $this),
            $policy
        );

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($checkPolicy->getConnections() as $connection) {
            if ($connection->getLinkedUser()->getEmail() == static::generateEmail('invite-accept-0', $this)) {
                $connectionFound = true;
                $this->assertEquals(7.10, $connection->getTotalValue());
            }
        }
        $this->assertTrue($connectionFound);
    }

    public function testAcceptConnectionReductionPromoConnectionOnly()
    {
        $policy = $this->createAndLink(static::generateEmail('user-promo', $this), new \DateTime('2016-01-01'));
        $policy->setPromoCode(PhonePolicy::PROMO_LAUNCH);
        static::$dm->flush();

        $this->assertEquals(83.88, $policy->getMaxPot());
        // 83.88 / 15 = 5.6
        for ($i = 1; $i <= 5; $i++) {
            $this->createAndLink(
                static::generateEmail(sprintf('invite-promo-%d', $i), $this),
                new \DateTime('2016-02-01'),
                static::generateEmail('user-promo', $this),
                $policy
            );
        }

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($checkPolicy->getConnections() as $connection) {
            $connectionFound = true;
            $this->assertEquals(15, $connection->getTotalValue());
        }
        $this->assertTrue($connectionFound);

        $this->createAndLink(
            static::generateEmail('invite-promo-0', $this),
            new \DateTime('2016-02-01'),
            static::generateEmail('user-promo', $this),
            $policy
        );

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        // 83.88 - 15 * 5 = 8.88
        foreach ($checkPolicy->getConnections() as $connection) {
            if ($connection->getLinkedUser()->getEmail() == static::generateEmail('invite-promo-0', $this)) {
                $connectionFound = true;
                $this->assertEquals(8.88, $connection->getTotalValue());
                $this->assertEquals(8.88, $connection->getValue());
                $this->assertEquals(0, $connection->getPromoValue());
            }
        }
        $this->assertTrue($connectionFound);
    }

    public function testAcceptConnectionReductionPromoConnectionRollover()
    {
        $policy = $this->createAndLink(
            static::generateEmail('user-promor', $this),
            new \DateTime('2016-01-01'),
            null,
            null,
            static::$phone2
        );
        $policy->setPromoCode(PhonePolicy::PROMO_LAUNCH);
        static::$dm->flush();

        $this->assertEquals(103.68, $policy->getMaxPot());
        // 103.68 / 15 = 6.9
        for ($i = 1; $i <= 6; $i++) {
            $this->createAndLink(
                static::generateEmail(sprintf('invite-promor-%d', $i), $this),
                new \DateTime('2016-02-01'),
                static::generateEmail('user-promor', $this),
                $policy
            );
        }

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($checkPolicy->getConnections() as $connection) {
            $connectionFound = true;
            $this->assertEquals(15, $connection->getTotalValue());
        }
        $this->assertTrue($connectionFound);

        $this->createAndLink(
            static::generateEmail('invite-promor-0', $this),
            new \DateTime('2016-02-01'),
            static::generateEmail('user-promor', $this),
            $policy
        );

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        // 103.68 - 15 * 6 = 13.68
        foreach ($checkPolicy->getConnections() as $connection) {
            if ($connection->getLinkedUser()->getEmail() == static::generateEmail('invite-promor-0', $this)) {
                $connectionFound = true;
                $this->assertEquals(13.68, $connection->getTotalValue());
                $this->assertEquals(10, $connection->getValue());
                $this->assertEquals(3.68, $connection->getPromoValue());
            }
        }
        $this->assertTrue($connectionFound);
    }

    public function testOptOut()
    {
        $email = static::generateEmail('optout', $this);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(EmailOptOut::class);
        $this->assertEquals(0, count($repo->findBy(['email' => strtolower($email)])));

        static::$invitationService->optout($email, EmailOptOut::OPTOUT_CAT_ALL);
        $this->assertEquals(1, count($repo->findBy(['email' => strtolower($email)])));

        static::$invitationService->optout($email, EmailOptOut::OPTOUT_CAT_ALL);
        $this->assertEquals(1, count($repo->findBy(['email' => strtolower($email)])));
    }

    public function testRejectAllInvitations()
    {
        $email = static::generateEmail('reject', $this);
        $userA = static::createUser(
            static::$userManager,
            static::generateEmail('user-rejectA', $this),
            'bar'
        );
        $policyA = static::initPolicy($userA, static::$dm, static::$phone, null, false, true);

        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('user-rejectB', $this),
            'bar'
        );
        $policyB = static::initPolicy($userB, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            $email,
            'bar'
        );
        $cancelledInvitation = self::$invitationService->inviteByEmail(
            $policyA,
            $email
        );
        $this->assertTrue($cancelledInvitation instanceof EmailInvitation);

        $this->expectInvitationEvent(InvitationEvent::EVENT_CANCELLED, 'onInvitationCancelledEvent');

        self::$invitationService->cancel($cancelledInvitation);

        $openInvitation = self::$invitationService->inviteByEmail(
            $policyB,
            $email
        );
        $this->assertTrue($openInvitation instanceof EmailInvitation);

        static::$invitationService->rejectAllInvitations($email);

        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(EmailInvitation::class);

        $updatedCancelledInvitation = $repo->find($cancelledInvitation->getId());
        $this->assertTrue($updatedCancelledInvitation->isCancelled());
        $this->assertFalse($updatedCancelledInvitation->isRejected());

        $updatedOpenInvitaiton = $repo->find($openInvitation->getId());
        $this->assertTrue($updatedOpenInvitaiton->isRejected());
    }

    public function testAddReward()
    {
        $policy = $this->createAndLink(static::generateEmail('testAddReward-A', $this), new \DateTime());
        $this->createAndLink(
            static::generateEmail('testAddReward-B', $this),
            new \DateTime(),
            static::generateEmail('testAddReward-A', $this),
            $policy
        );

        $reward = $this->createReward(static::generateEmail('testAddReward-R', $this));
        $connection = static::$invitationService->addReward($policy->getUser(), $reward, 10);
        $this->assertEquals(20, $policy->getPotValue());
        $this->assertEquals(2, count($policy->getConnections()));
    }

    public function testResolveSCode()
    {
        $this->assertEquals('a', static::$invitationService->resolveSCode('a'));
        $this->assertEquals('abcdefgh', static::$invitationService->resolveSCode('abcdefgh'));

        $this->assertEquals(
            'abcdefgh',
            static::$invitationService->resolveSCode('https://goo.gl/wSZGbc'),
            'If error, try running webvagrant, as requires server running on localhost.'
        );
        $this->assertEquals(
            'abcdefgh',
            static::$invitationService->resolveSCode('goo.gl/wSZGbc'),
            'If error, try clearing cache.'
        );

        $this->assertEquals('abcdefgh', static::$invitationService->resolveSCode(
            'https://sosure.test-app.link/GQnmCNBSwB'
        ));
        $this->assertEquals('abcdefgh', static::$invitationService->resolveSCode(
            'sosure.test-app.link/GQnmCNBSwB'
        ));
    }

    private function createAndLink($email, $date, $inviteeEmail = null, $policy = null, $phone = null)
    {
        if ($phone == null) {
            $phone = static::$phone;
        }
        $user = static::createUser(
            static::$userManager,
            $email,
            'bar'
        );
        $newPolicy = static::initPolicy($user, static::$dm, $phone, $date, false, true);

        if ($inviteeEmail) {
            $invitation = self::$invitationService->inviteByEmail(
                $newPolicy,
                $inviteeEmail
            );
            $this->assertTrue($invitation instanceof EmailInvitation);
            self::$invitationService->accept($invitation, $policy, $date);
        }

        return $newPolicy;
    }

    private function expectInvitationEvent($eventType, $method)
    {
        $listener = $this->getMockBuilder('IntercomListener')
                         ->setMethods(array($method))
                         ->getMock();
        $listener->expects($this->once())
                     ->method($method);

        $dispatcher = static::$container->get('event_dispatcher');
        $dispatcher->addListener($eventType, array($listener, $method));
    }
}
