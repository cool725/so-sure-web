<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Tests\Create;
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
        $this->assertTrue(self::$policyVoter->supports('connect', $policy));
        $this->assertTrue(self::$policyVoter->supports('cashback', $policy));
        $this->assertTrue(self::$policyVoter->supports('renew', $policy));
        $this->assertTrue(self::$policyVoter->supports('repurchase', $policy));
        $this->assertTrue(self::$policyVoter->supports('upgrade', $policy));
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

    public function testVoteRepurchase()
    {
        $user = new User();
        $user->setId(1);
        $user->setEnabled(true);
        self::addAddress($user);
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        $token = new PreAuthenticatedToken($user, '1', 'test');

        $this->assertTrue(self::$policyVoter->voteOnAttribute(PolicyVoter::REPURCHASE, $policy, $token));

        $user->setEnabled(false);
        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::REPURCHASE, $policy, $token));
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

        $policy->setStatus(Policy::STATUS_EXPIRED_WAIT_CLAIM);
        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::EDIT, $policy, $token));

        $policy->setStatus(Policy::STATUS_EXPIRED);
        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::EDIT, $policy, $token));
    }

    /**
     * Tests that upgrade is only allowed for helvetia policies and that the normal rules apply too.
     */
    public function testVoteUpgrade()
    {
        $userA = Create::user();
        $userB = Create::user();
        $policyA = Create::policy($userA, '2019-01-01', Policy::STATUS_ACTIVE, 12);
        $policyB = Create::policy($userA, '2020-01-01', Policy::STATUS_ACTIVE, 12);
        $policyC = Create::policy($userB, '2020-01-01', Policy::STATUS_CANCELLED, 12);
        // Test with the first user that they can upgrade their helvetia policy and not their salva.
        $token = new PreAuthenticatedToken($userA, '1', 'test');
        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::UPGRADE, $policyA, $token));
        $this->assertTrue(self::$policyVoter->voteOnAttribute(PolicyVoter::UPGRADE, $policyB, $token));
        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::UPGRADE, $policyC, $token));
        // Now test that the second guy can only upgrade his own policy.
        $token = new PreAuthenticatedToken($userB, '1', 'test');
        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::UPGRADE, $policyA, $token));
        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::UPGRADE, $policyB, $token));
        $this->assertFalse(self::$policyVoter->voteOnAttribute(PolicyVoter::UPGRADE, $policyC, $token));
    }
}
