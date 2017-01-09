<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;

/**
 * @group functional-nonet
 */
class UserVoterTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $userVoter;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$userVoter = self::$container->get('app.voter.user');
    }

    public function tearDown()
    {
    }

    public function testSupportsUnknown()
    {
        $user = new User();
        $this->assertFalse(self::$userVoter->supports('unknown', $user));
        $this->assertFalse(self::$userVoter->supports('view', null));
    }

    public function testSupports()
    {
        $user = new User();
        $this->assertTrue(self::$userVoter->supports('view', $user));
        $this->assertTrue(self::$userVoter->supports('edit', $user));
        $this->assertTrue(self::$userVoter->supports('add-policy', $user));
    }

    public function testVoteOk()
    {
        $user = new User();
        $user->setId(1);
        $token = new PreAuthenticatedToken($user, '1', 'test');

        $this->assertTrue(self::$userVoter->voteOnAttribute('view', $user, $token));
    }

    public function testVoteDiffUser()
    {
        $user = new User();
        $user->setId(1);

        $userDiff = new User();
        $userDiff->setId(2);
        $token = new PreAuthenticatedToken($userDiff, '1', 'test');

        $this->assertFalse(self::$userVoter->voteOnAttribute('view', $user, $token));
    }

    public function testVoteUserDisabled()
    {
        $user = new User();
        $user->setId(1);
        $user->setEnabled(false);
        $token = new PreAuthenticatedToken($user, '1', 'test');

        $this->assertFalse(self::$userVoter->voteOnAttribute('add-policy', $user, $token));
    }

    public function testVoteUserLocked()
    {
        $user = new User();
        $user->setId(1);
        $user->setLocked(true);
        $token = new PreAuthenticatedToken($user, '1', 'test');

        $this->assertFalse(self::$userVoter->voteOnAttribute('add-policy', $user, $token));
    }
}
