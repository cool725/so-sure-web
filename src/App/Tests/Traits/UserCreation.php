<?php
namespace App\Tests\Traits;

use AppBundle\Document\User;
use AppBundle\Document\Policy;

trait UserCreation
{
    use \AppBundle\Tests\UserClassTrait;

    private $rewardPotValue;

    /**
     * Make a user, with a policy
     *
     * @see \AppBundle\Tests\Controller\UserControllerTest::testUserInvite();
     */
    public function generateUserWithTwoPolicies(float $rewardPotValue = 0): User
    {
        $this->rewardPotValue = $rewardPotValue;

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
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPotValue($this->rewardPotValue);

        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $phone2 = self::getRandomPhone(self::$dm);
        $policy = self::initPolicy($user, self::$dm, $phone2, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        self::$dm->flush();

        return $user;
    }

    protected function assertSummaryMatchesUserWithTwoPolicies(array $summary)
    {
        $this->assertNotNull($summary);
        $this->assertNotEmpty($summary);

        $this->assertArrayHasKey('name', $summary);
        $this->assertArrayHasKey('policies', $summary);

        $this->assertCount(2, $summary['policies']);
        $this->assertPolicySummaryHaveKeys($summary['policies'][0]);
        $this->assertPolicySummaryHaveKeys($summary['policies'][1]);

        $policy = $summary['policies'][0];

            # Not yet adding connections
        $this->assertEquals(
            $this->rewardPotValue,
            $policy['rewardPot'],
            'Expected the reward pot to have a value',
            0.02    // float delta
        );
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
