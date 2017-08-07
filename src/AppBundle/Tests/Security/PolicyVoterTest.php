<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use AppBundle\Security\PolicyVoter;

/**
 * @group functional-nonet
 */
class PolicyVoterTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

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
        $policy = new SalvaPhonePolicy();
        $this->assertFalse(self::$policyVoter->supports('unknown', $policy));
        $this->assertFalse(self::$policyVoter->supports('view', null));
    }

    public function testSupports()
    {
        $policy = new SalvaPhonePolicy();
        $this->assertTrue(self::$policyVoter->supports('view', $policy));
        $this->assertTrue(self::$policyVoter->supports('edit', $policy));
        $this->assertTrue(self::$policyVoter->supports('send-invitation', $policy));
    }

    public function testVoteOk()
    {
        $user = new User();
        $user->setId(1);
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        $token = new PreAuthenticatedToken($user, '1', 'test');

        $this->assertTrue(self::$policyVoter->voteOnAttribute(PolicyVoter::VIEW, $policy, $token));
    }

    public function testVoteDiffUser()
    {
        $user = new User();
        $user->setId(1);
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);

        $userDiff = new User();
        $userDiff->setId(2);
        $token = new PreAuthenticatedToken($userDiff, '1', 'test');

        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::VIEW, $policy, $token));
    }

    public function testVoteEditExpired()
    {
        $user = new User();
        $user->setId(1);
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $token = new PreAuthenticatedToken($user, '1', 'test');

        $this->assertTrue(self::$policyVoter->voteOnAttribute(PolicyVoter::EDIT, $policy, $token));

        $policy->setStatus(Policy::STATUS_EXPIRED_CLAIMABLE);
        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::EDIT, $policy, $token));

        $policy->setStatus(Policy::STATUS_EXPIRED);
        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::EDIT, $policy, $token));
    }
}
