<?php
namespace App\Tests\Normalizer;

use App\Normalizer\UserPolicySummary;
use AppBundle\Document\Policy;
use AppBundle\Tests\Controller\BaseControllerTest;

class UserPolicySummaryTest extends BaseControllerTest
{
    use \AppBundle\Tests\UserClassTrait;

    /**
     * A test user has one policy.
     */
    public function testOnePolicySummary()
    {
        $rewardPotValue = 11.97;

        $email = $this->generateUserWithTwoPolicies($rewardPotValue);
        $user = self::$userManager->findUserByEmail($email);

        /** @var UserPolicySummary $userPolicySummary */
        $userPolicySummary = self::$container->get('test.App\Normalizer\UserPolicySummary');
        $summary = $userPolicySummary->shortPolicySummary($user);

        $this->assertNotNull($summary);
        $this->assertNotEmpty($summary);

        $this->assertArrayHasKey('name', $summary);
        $this->assertArrayHasKey('policies', $summary);

        $this->assertCount(2, $summary['policies']);
        $this->assertPolicySummaryHaveKeys($summary['policies'][0]);
        $this->assertPolicySummaryHaveKeys($summary['policies'][1]);

        $policy = $summary['policies'][0];

        ## Not yet adding connections, or adding to the rewardPot
        $this->assertEquals(
            $rewardPotValue,
            $policy['rewardPot'],
            'Expected the reward pot to have a value',
            0.02    // float delta
        );
        #$this->assertSame(1, $policy['connections']);
    }

    /**
     * Make a user, with a policy
     *
     * @see \AppBundle\Tests\Controller\UserControllerTest::testUserInvite();
     */
    public function generateUserWithTwoPolicies($rewardPotValue = 10): string
    {
        $email = self::generateEmail('testUserInvite-inviter'.random_int(PHP_INT_MIN, PHP_INT_MAX), $this);
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
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPotValue($rewardPotValue);

        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $phone2 = self::getRandomPhone(self::$dm);
        $policy = self::initPolicy($user, self::$dm, $phone2, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        self::$dm->flush();

        return $email;
    }

    private function assertPolicySummaryHaveKeys(array $policy)
    {
        $this->assertArrayHasKey('policyNumber', $policy);
        $this->assertArrayHasKey('endDate', $policy);
        $this->assertArrayHasKey('insuredPhone', $policy);
        $this->assertArrayHasKey('connections', $policy);
        $this->assertArrayHasKey('rewardPot', $policy);
        $this->assertArrayHasKey('rewardPotCurrency', $policy);

        $this->assertSame('GBP', $policy['rewardPotCurrency']);
    }
}
