<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Invitation\EmailInvitation;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;

/**
 * @group functional-nonet
 */
class InvitationVoterTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $invitationVoter;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$invitationVoter = self::$container->get('app.voter.invitation');
    }

    public function tearDown()
    {
    }

    public function testSupportsUnknown()
    {
        $invitation = new EmailInvitation();
        $this->assertFalse(self::$invitationVoter->supports('unknown', $invitation));
        $this->assertFalse(self::$invitationVoter->supports('accept', null));
    }

    public function testSupports()
    {
        $invitation = new EmailInvitation();
        $this->assertTrue(self::$invitationVoter->supports('accept', $invitation));
        $this->assertTrue(self::$invitationVoter->supports('reject', $invitation));
        $this->assertTrue(self::$invitationVoter->supports('cancel', $invitation));
        $this->assertTrue(self::$invitationVoter->supports('reinvite', $invitation));
    }

    public function testVoteOk()
    {
        $user = new User();
        $user->setId(1);
        $invitation = new EmailInvitation();
        $invitation->setInviter($user);
        $token = new PreAuthenticatedToken($user, '1', 'test');

        $this->assertTrue(self::$invitationVoter->voteOnAttribute('cancel', $invitation, $token));
        $this->assertTrue(self::$invitationVoter->voteOnAttribute('reinvite', $invitation, $token));

        $this->assertFalse(self::$invitationVoter->voteOnAttribute('accept', $invitation, $token));
        $this->assertFalse(self::$invitationVoter->voteOnAttribute('reject', $invitation, $token));
    }

    public function testVoteDiffUser()
    {
        $user = new User();
        $user->setId(1);
        $invitation = new EmailInvitation();
        $invitation->setInviter($user);

        $userDiff = new User();
        $userDiff->setId(2);
        $token = new PreAuthenticatedToken($userDiff, '1', 'test');

        $this->assertFalse(self::$invitationVoter->voteOnAttribute('cancel', $invitation, $token));
    }
}
