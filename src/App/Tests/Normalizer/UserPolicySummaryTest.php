<?php
namespace App\Tests\Normalizer;

use App\Normalizer\UserPolicySummary;
use App\Tests\Traits;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Tests\Controller\BaseControllerTest;

class UserPolicySummaryTest extends BaseControllerTest
{
    use Traits\UserCreation;

    public function testTwoPolicySummary()
    {
        $rewardPotValue = 11.97;
        $user = $this->generateUserWithTwoPolicies($rewardPotValue);

        /** @var UserPolicySummary $userPolicySummary */
        $userPolicySummary = self::$container->get('test.App\Normalizer\UserPolicySummary');

        $summary = $userPolicySummary->shortPolicySummary($user);
        $this->assertSummaryMatchesUserWithTwoPolicies($summary, $rewardPotValue);
    }

    /**
     * A policy-holder with two policies, but only one is active (or unpaid)
     */
    public function testOneActivePolicySummary()
    {
        $rewardPotValue = 11.97;
        $user = $this->generateUserWithOneValidPolicyAndCancelled($rewardPotValue);

        /** @var UserPolicySummary $userPolicySummary */
        $userPolicySummary = self::$container->get('test.App\Normalizer\UserPolicySummary');

        $summary = $userPolicySummary->shortPolicySummary($user);

        $this->assertNotNull($summary);
        $this->assertNotEmpty($summary);

        $this->assertArrayHasKey('name', $summary);
        $this->assertArrayHasKey('policies', $summary);
        $this->assertCount(1, $summary['policies']);

        $this->assertPolicySummaryHaveKeys($summary['policies'][0]);
    }

    /**
     * Make a user, with one cancelled policy + a good one
     */
    private function generateUserWithOneValidPolicyAndCancelled(float $rewardPotValue = 0): User
    {
        $email = self::generateEmail('testUser'.random_int(PHP_INT_MIN, PHP_INT_MAX), $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasPolicy(), 'expected to have a (cancelled) policy');
        $this->assertFalse($policy->getUser()->hasActivePolicy(), 'expected policy to not be active');

        $phone2 = self::getRandomPhone(self::$dm);
        $policy = self::initPolicy($user, self::$dm, $phone2, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPotValue($rewardPotValue);

        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy(), 'expected 2nd policy to be active');

        return $user;
    }
}
