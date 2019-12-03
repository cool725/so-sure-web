<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Policy;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Document\\InvitationTest
 */
class InvitationTest extends WebTestCase
{
    use \AppBundle\Tests\UserClassTrait;
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;

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
    }

    public function tearDown()
    {
    }

    public function testInviteSetsReinvite()
    {
        $user = new User();
        $policy = new HelvetiaPhonePolicy();
        $user->addPolicy($policy);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $invitation = new EmailInvitation();
        $policy->addInvitation($invitation);
        $this->assertEquals(0, $invitation->getReinvitedCount());
        $date = new \DateTime('2016-01-01');
        $invitation->invite($date);
        $date->add(new \DateInterval('P1D'));
        $this->assertEquals($date, $invitation->getNextReinvited());
    }

    public function testInviteCannotImmediatelyReinvite()
    {
        $user = new User();
        $policy = new HelvetiaPhonePolicy();
        $user->addPolicy($policy);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $invitation = new EmailInvitation();
        $policy->addInvitation($invitation);
        $invitation->invite();
        $this->assertFalse($invitation->canReinvite());
    }

    public function testReinvite()
    {
        $user = new User();
        $policy = new HelvetiaPhonePolicy();
        $user->addPolicy($policy);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $invitation = new EmailInvitation();
        $policy->addInvitation($invitation);
        $this->assertEquals(0, $invitation->getReinvitedCount());
        $date = new \DateTime('2016-01-01');
        for ($i = 0; $i < $invitation->getMaxReinvitations(); $i++) {
            $invitation->reinvite($date);
            $this->assertTrue($invitation->canReinvite());
            $this->assertEquals($date, $invitation->getLastReinvited());
            $this->assertEquals($i+1, $invitation->getReinvitedCount());

            $date->add(new \DateInterval('P1D'));
            $this->assertEquals($date, $invitation->getNextReinvited());
        }
        $invitation->reinvite();
        $this->assertFalse($invitation->canReinvite());
        $this->assertNull($invitation->getNextReinvited());
    }

    /**
     * @expectedException \Exception
     */
    public function testTooManyReinvite()
    {
        $user = new User();
        $policy = new HelvetiaPhonePolicy();
        $user->addPolicy($policy);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $invitation = new EmailInvitation();
        $policy->addInvitation($invitation);
        $this->assertEquals(0, $invitation->getReinvitedCount());
        $invitation->reinvite();
        $invitation->reinvite();
    }

    /**
     * @expectedException \Exception
     */
    public function testReinviteCancelledPolicy()
    {
        $user = new User();
        $policy = new HelvetiaPhonePolicy();
        $user->addPolicy($policy);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $invitation = new EmailInvitation();
        $policy->addInvitation($invitation);
        $this->assertEquals(0, $invitation->getReinvitedCount());
        $invitation->reinvite();
    }

    public function testMobileNumberIsNormalized()
    {
        $invitationA = new SmsInvitation();
        $invitationA->setMobile('07700 900000');
        $this->assertEquals('+447700900000', $invitationA->getMobile());

        $invitationB = new SmsInvitation();
        $invitationB->setMobile('00447700 900000');
        $this->assertEquals('+447700900000', $invitationB->getMobile());
    }

    public function testToApiArrayNoDebug()
    {
        $invitation = new EmailInvitation();
        $api = $invitation->toApiArray();
        $this->assertFalse(isset($api['inviter_id']));
    }

    public function testImageUrlLinkedUser()
    {
        $inviter = new User();
        $inviter->setEmail('bar@foo.com');
        $inviter->setFacebookId('2');

        $user = new User();
        $user->setEmail('foo@bar.com');
        $user->setFacebookId('1');

        $invitation = new EmailInvitation();
        $invitation->setEmail('foo@bar.com');
        $invitation->setName('Foo Bar');
        $invitation->setInvitee($user);
        $invitation->setInviter($inviter);

        $api = $invitation->toApiArray();
        $this->assertEquals(
            'https://graph.facebook.com/1/picture?width=100&height=100',
            $api['image_url']
        );

        $api = $invitation->toApiArray(true);
        $this->assertEquals(
            'https://graph.facebook.com/2/picture?width=100&height=100',
            $api['image_url']
        );
    }

    public function testImageUrlEmailWithName()
    {
        $invitation = new EmailInvitation();
        $invitation->setEmail('foo@bar.com');
        $invitation->setName('Foo Bar');
        $api = $invitation->toApiArray();
        $this->assertEquals(
            'https://www.gravatar.com/avatar/f3ada405ce890b6f8204094deb12d8a8?d=404&s=100',
            $api['image_url']
        );
    }

    public function testImageUrlEmailWithoutName()
    {
        $invitation = new EmailInvitation();
        $invitation->setEmail('foo@bar.com');
        $api = $invitation->toApiArray();
        $this->assertEquals(
            'https://www.gravatar.com/avatar/f3ada405ce890b6f8204094deb12d8a8?d=404&s=100',
            $api['image_url']
        );
    }

    public function testImageUrlSmsWithoutName()
    {
        $invitation = new SmsInvitation();
        $api = $invitation->toApiArray();
        $this->assertNull($api['image_url']);
    }

    public function testImageUrlSmsWithName()
    {
        $invitation = new SmsInvitation();
        $invitation->setName('Foo Bar');
        $api = $invitation->toApiArray();
        $this->assertNull($api['image_url']);
    }

    public function testNonDuplicateEmail()
    {
        $userA = new User();
        $userA->setEmail('testNonDuplicateEmailA@InvitationTest.com');
        $policyA = new HelvetiaPhonePolicy();
        $userA->addPolicy($policyA);

        $invitationA = new EmailInvitation();
        $invitationA->setName('Foo Bar');
        $invitationA->setEmail('testNonDuplicateEmail@bar.com');
        $policyA->addInvitation($invitationA);
        self::$dm->persist($userA);
        self::$dm->persist($policyA);
        self::$dm->persist($invitationA);
        self::$dm->flush();

        $userB = new User();
        $userB->setEmail('testNonDuplicateEmailB@InvitationTest.com');
        $policyB = new HelvetiaPhonePolicy();
        $userB->addPolicy($policyB);

        $invitationB = new EmailInvitation();
        $invitationB->setName('Foo Bar');
        $invitationB->setEmail('testNonDuplicateEmail@bar.com');
        $policyB->addInvitation($invitationB);
        self::$dm->persist($userB);
        self::$dm->persist($policyB);
        self::$dm->persist($invitationB);
        self::$dm->flush();

        $invitationC = new EmailInvitation();
        $invitationC->setName('Foo Bar');
        $invitationC->setEmail('testNonDuplicateEmail-different@bar.com');
        $policyB->addInvitation($invitationC);
        self::$dm->persist($invitationC);
        self::$dm->flush();

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testNonDuplicateSms()
    {
        $userA = new User();
        $userA->setEmail('testNonDuplicateSmsA@InvitationTest.com');
        $policyA = new HelvetiaPhonePolicy();
        $userA->addPolicy($policyA);

        $invitationA = new SmsInvitation();
        $invitationA->setName('Foo Bar');
        $invitationA->setMobile('+447775740401');
        self::$dm->persist($userA);
        self::$dm->persist($policyA);
        self::$dm->persist($invitationA);
        self::$dm->flush();

        $userB = new User();
        $userB->setEmail('testNonDuplicateSmsB@InvitationTest.com');
        $policyB = new HelvetiaPhonePolicy();
        $userB->addPolicy($policyB);

        $invitationB = new SmsInvitation();
        $invitationB->setName('Foo Bar');
        $invitationB->setMobile('+447775740402');
        $policyB->addInvitation($invitationB);
        self::$dm->persist($userB);
        self::$dm->persist($policyB);
        self::$dm->persist($invitationB);
        self::$dm->flush();

        $invitationC = new SmsInvitation();
        $invitationC->setName('Foo Bar');
        $invitationC->setMobile('+447775740403');
        $policyB->addInvitation($invitationC);
        self::$dm->persist($invitationC);
        self::$dm->flush();

        // test is if the above generates an exception
        $this->assertTrue(true);
    }
}
