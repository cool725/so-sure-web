<?php
namespace App\Tests\Normalizer;

use App\Normalizer\UserPolicySummary;
use App\Tests\Traits;
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
        $this->assertSummaryMatchesUserWithTwoPolicies($summary);
    }
}
