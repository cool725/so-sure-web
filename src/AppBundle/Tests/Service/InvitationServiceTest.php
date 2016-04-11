<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Service\InvitationService;
use Doctrine\ODM\MongoDB\DocumentManager;

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
         $mailer = \Swift_Mailer::newInstance($transport);
         self::$invitationService = new InvitationService(
             self::$dm,
             self::$container->get('logger'),
             $mailer,
             self::$container->get('templating'),
             self::$container->get('api.router'),
             self::$container->get('app.shortlink'),
             self::$container->get('app.sms')
         );

         self::$invitationService->setDebug(true);
    }

    public function tearDown()
    {
    }
    
    /**
     * @expectedException \InvalidArgumentException
     */
    public function testDuplicateEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user1', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm);
        $invitation = self::$invitationService->email($policy, static::generateEmail('invite1', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);

        self::$invitationService->email($policy, static::generateEmail('invite1', $this));
    }
    
    public function testOptOutCatIntivationsEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user2', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm);

        $optOut = new EmailOptOut();
        $optOut->setEmail(static::generateEmail('invite2', $this));
        $optOut->setCategory(EmailOptOut::OPTOUT_CAT_INVITATIONS);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->email($policy, static::generateEmail('invite2', $this));
        $this->assertNull($invitation);
    }

    public function testOptOutCatAllEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user3', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm);

        $optOut = new EmailOptOut();
        $optOut->setEmail(static::generateEmail('invite3', $this));
        $optOut->setCategory(EmailOptOut::OPTOUT_CAT_ALL);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->email($policy, static::generateEmail('invite3', $this));
        $this->assertNull($invitation);
    }

    public function testNoOptOutEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user4', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm);

        $invitation = self::$invitationService->email($policy, static::generateEmail('invite4', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testDuplicateSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser1', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm);
        $invitation = self::$invitationService->sms($policy, '1123');
        $this->assertTrue($invitation instanceof SmsInvitation);

        self::$invitationService->sms($policy, '1123');
    }
    
    public function testOptOutCatIntivationsSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser2', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm);

        $optOut = new SmsOptOut();
        $optOut->setMobile('11234');
        $optOut->setCategory(SmsOptOut::OPTOUT_CAT_INVITATIONS);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->sms($policy, '11234');
        $this->assertNull($invitation);
    }

    public function testOptOutCatAllSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser3', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm);

        $optOut = new SmsOptOut();
        $optOut->setMobile('112345');
        $optOut->setCategory(EmailOptOut::OPTOUT_CAT_ALL);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->sms($policy, '112345');
        $this->assertNull($invitation);
    }

    public function testNoOptOutSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser4', $this),
            'bar'
        );
        $policy = static::createPolicy($user, static::$dm);

        $invitation = self::$invitationService->sms($policy, '1123456');
        $this->assertTrue($invitation instanceof SmsInvitation);
    }
}
