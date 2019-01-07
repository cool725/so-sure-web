<?php

namespace AppBundle\Tests\Service;

use AppBundle\Repository\Invitation\EmailInvitationRepository;
use AppBundle\Service\MixpanelService;
use AppBundle\Service\RouterService;
use Aws\S3\S3Client;
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
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;

use AppBundle\Service\InvitationService;
use AppBundle\Service\MailerService;

use AppBundle\Event\InvitationEvent;

use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\FullPotException;
use AppBundle\Exception\ClaimException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\ConnectedInvitationException;
use Symfony\Component\Templating\EngineInterface;

/**
 * @group functional-nonet
 */
class InvitationServiceAdditionalTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $userRepo;
    protected static $invitationService;
    protected static $phone2;

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
        self::$userManager = self::$container->get('fos_user.user_manager');
        $transport = new \Swift_Transport_NullTransport(new \Swift_Events_SimpleEventDispatcher);
        /** @var EngineInterface $templating */
        $templating = self::$container->get('templating');
        /** @var RouterService $router */
        $router = self::$container->get('app.router');
        /** @var MixpanelService $mixpanelService */
        $mixpanelService = self::$container->get('app.mixpanel');
        /** @var S3Client $s3Client */
        $s3Client = self::$container->get('aws.s3');
        $mailer = new MailerService(
            new \Swift_Mailer($transport),
            $transport,
            $templating,
            $router,
            'foo@foo.com',
            'bar',
            $mixpanelService,
            $s3Client,
            self::$container->getParameter('kernel.environment'),
            $dm
        );
        /** @var InvitationService invitationService */
        $invitationService = self::$container->get('app.invitation');
        $invitationService->setMailer($mailer);
        $invitationService->setDebug(true);
        self::$invitationService = $invitationService;

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

    public function testEmailInvitationReinvite()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user5', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite5', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite5', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);

        /** @var EmailInvitationRepository $emailInvitationRepo */
        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        $count = count($emailInvitationRepo->findSystemReinvitations());
        $future = \DateTime::createFromFormat('U', time());
        $future->add(new \DateInterval('P3D'));
        $this->assertEquals($count + 1, count($emailInvitationRepo->findSystemReinvitations($future)));

        // allow reinvitation
        $this->expectInvitationEvent(InvitationEvent::EVENT_REINVITED, 'onInvitationReinvitedEvent');

        $invitation->setNextReinvited(new \DateTime('2016-01-01'));
        self::$invitationService->reinvite($invitation);

        $this->assertEquals(1, $invitation->getReinvitedCount());
        $this->assertEquals($count, count($emailInvitationRepo->findSystemReinvitations($future)));
    }

    /**
     * @expectedException AppBundle\Exception\OptOutException
     */
    public function testEmailOptOutInvite()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testEmailOptOutInvite-user', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $optOut = new EmailOptOut();
        $optOut->setEmail(static::generateEmail('testEmailOptOutInvite-invite', $this));
        $optOut->addCategory(SmsOptOut::OPTOUT_CAT_INVITATIONS);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('testEmailOptOutInvite-invite', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);
    }

    public function testEmailOptOutMarketingInvite()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testEmailOptOutMarketingInvite-user', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $optOut = new EmailOptOut();
        $optOut->setEmail(static::generateEmail('testEmailOptOutInvite-invite', $this));
        $optOut->addCategory(SmsOptOut::OPTIN_CAT_MARKETING);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('testEmailOptOutMarketingInvite-invite', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);
    }

    public function testEmailInvitationReinviteOptOut()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('EmailInvitationReinviteOptOut-user', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('EmailInvitationReinviteOptOut-invite', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('EmailInvitationReinviteOptOut-invite', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        /** @var EmailInvitationRepository $emailInvitationRepo */
        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        $count = count($emailInvitationRepo->findSystemReinvitations());
        $future = \DateTime::createFromFormat('U', time());
        $future->add(new \DateInterval('P3D'));
        $this->assertEquals($count + 1, count($emailInvitationRepo->findSystemReinvitations($future)));

        $optOut = new EmailOptOut();
        $optOut->setEmail(static::generateEmail('EmailInvitationReinviteOptOut-invite', $this));
        $optOut->addCategory(SmsOptOut::OPTOUT_CAT_INVITATIONS);
        static::$dm->persist($optOut);
        static::$dm->flush();

        // allow reinvitation
        $invitation->setNextReinvited(new \DateTime('2016-01-01'));
        self::$invitationService->reinvite($invitation);

        $this->assertEquals(EmailInvitation::STATUS_SKIPPED, $invitation->getStatus());
        $this->assertEquals($count, count($emailInvitationRepo->findSystemReinvitations($future)));
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
     * @expectedException AppBundle\Exception\ProcessedException
     */
    public function testEmailReinviteProcessed()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-processed', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-processed', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-processed', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        /** @var EmailInvitationRepository $emailInvitationRepo */
        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        $count = count($emailInvitationRepo->findSystemReinvitations());
        $future = \DateTime::createFromFormat('U', time());
        $future->add(new \DateInterval('P3D'));
        $this->assertEquals($count + 1, count($emailInvitationRepo->findSystemReinvitations($future)));
        
        $invitation->setAccepted(\DateTime::createFromFormat('U', time()));
        static::$dm->flush();
        $this->assertEquals($count, count($emailInvitationRepo->findSystemReinvitations($future)));

        self::$invitationService->reinvite($invitation);
    }

    public function testEmailInvitationCancel()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user6', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $this->assertTrue($policy->isPolicy());

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite6', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);

        /** @var EmailInvitationRepository $emailInvitationRepo */
        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        $count = count($emailInvitationRepo->findSystemReinvitations());
        $future = \DateTime::createFromFormat('U', time());
        $future->add(new \DateInterval('P3D'));
        $this->assertEquals($count + 1, count($emailInvitationRepo->findSystemReinvitations($future)));

        self::$invitationService->cancel($invitation, Policy::CANCELLED_ACTUAL_FRAUD);

        $this->assertTrue($invitation->isCancelled());
        $this->assertEquals($count, count($emailInvitationRepo->findSystemReinvitations($future)));
    }

    public function testEmailInvitationReject()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user7', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $this->assertTrue($policy->isPolicy());

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite7', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);

        /** @var EmailInvitationRepository $emailInvitationRepo */
        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        $count = count($emailInvitationRepo->findSystemReinvitations());
        $future = \DateTime::createFromFormat('U', time());
        $future->add(new \DateInterval('P3D'));
        $this->assertEquals($count + 1, count($emailInvitationRepo->findSystemReinvitations($future)));

        $this->expectInvitationEvent(InvitationEvent::EVENT_REJECTED, 'onInvitationRejectedEvent');

        self::$invitationService->reject($invitation);

        $this->assertTrue($invitation->isRejected());
        $this->assertEquals($count, count($emailInvitationRepo->findSystemReinvitations($future)));
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
