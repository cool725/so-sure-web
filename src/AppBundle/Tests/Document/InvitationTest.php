<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

/**
 * @group functional-nonet
 */
class InvitationTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
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
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
    }

    public function tearDown()
    {
    }

    public function testInviteSetsReinvite()
    {
        $invitation = new EmailInvitation();
        $this->assertEquals(0, $invitation->getReinvitedCount());
        $date = new \DateTime('2016-01-01');
        $invitation->invite($date);
        $date->add(new \DateInterval('P1D'));
        $this->assertEquals($date, $invitation->getNextReinvited());
    }

    public function testInviteCannotImmediatelyReinvite()
    {
        $invitation = new EmailInvitation();
        $invitation->invite();
        $this->assertFalse($invitation->canReinvite());
    }

    public function testReinvite()
    {
        $invitation = new EmailInvitation();
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
        $invitation = new EmailInvitation();
        $this->assertEquals(0, $invitation->getReinvitedCount());
        $invitation->reinvite();
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
}
