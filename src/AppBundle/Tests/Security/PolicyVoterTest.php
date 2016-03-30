<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;

/**
 * @group functional-nonet
 */
class PolicyVoterTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $policyVoter;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$policyVoter = self::$container->get('app.voter.policy');
    }

    public function tearDown()
    {
    }

    public function testSupportsUnknown()
    {
        $policy = new Policy();
        $this->assertFalse(self::$policyVoter->supports('unknown', $policy));
        $this->assertFalse(self::$policyVoter->supports('view', null));
    }

    public function testSupports()
    {
        $policy = new Policy();
        $this->assertTrue(self::$policyVoter->supports('view', $policy));
        $this->assertTrue(self::$policyVoter->supports('edit', $policy));
    }

    public function testVoteOk()
    {
        $user = new User();
        $user->setId(1);
        $policy = new Policy();
        $policy->setUser($user);
        $token = new PreAuthenticatedToken($user, '1', 'test');

        $this->assertTrue(self::$policyVoter->voteOnAttribute('view', $policy, $token));
    }

    public function testVoteDiffUser()
    {
        $user = new User();
        $user->setId(1);
        $policy = new Policy();
        $policy->setUser($user);

        $userDiff = new User();
        $userDiff->setId(2);
        $token = new PreAuthenticatedToken($userDiff, '1', 'test');

        $this->assertFalse(self::$policyVoter->voteOnAttribute('view', $policy, $token));
    }
}
