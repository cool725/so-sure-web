<?php
namespace App\Tests\Normalizer;

use App\Normalizer\UserPolicySummary;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Traits;
use AppBundle\Document\Policy;
use AppBundle\Tests\Controller\BaseControllerTest;
use stdClass;

/**
 * @group functional-nonet
 */
class UserPolicySummaryTest extends BaseControllerTest
{
    use Traits\UserCreation;

    /**
     * A policy-holder with two policies, but only one is active (or unpaid)
     */
    public function testOneActivePolicySummary()
    {
        $rewardPotValue = 11.97;
        $out = $this->generateUserWithLiveAndCancelledPolicies($rewardPotValue);
        /** @var Policy $policy */
        $policy = $out->activePolicy;
        $expiresDate = $policy->getEnd()->format('M jS Y');
        $connectionsCount = count($policy->getConnections());
        $pot = '£' . sprintf("%.02f", $rewardPotValue);

        /** @var UserPolicySummary $userPolicySummary */
        $userPolicySummary = self::$container->get('test.App\Normalizer\UserPolicySummary');

        $summaryWidget = $userPolicySummary->shortPolicySummary($out->user);

        $actualJson = json_encode($summaryWidget);
        // @codingStandardsIgnoreStart

        // the URL changes between local dev, build, etc. so build it & json-escape the string
        $router = self::$container->get('router');
        $userPageUrl = $router->generate('user_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $userPageUrl = str_replace('/', '\/', $userPageUrl);

        $expectedJson = <<<JSON
{
    "widgets": [{
        "type": "TEXT",
        "title": "So-Sure Policy {$policy->getPolicyNumber()} for your {$out->activePhone}",
        "text": "Expires on {$expiresDate}. You currently have {$connectionsCount} connections & your reward pot is worth {$pot}",
        "launchUrl": "{$userPageUrl}"
    }]
}
JSON;
        // @codingStandardsIgnoreEnd

        $this->assertNotNull($actualJson);
        $this->assertJsonStringEqualsJsonString($actualJson, $expectedJson);

        $this->assertNotNull($summaryWidget);
        $this->assertNotEmpty($summaryWidget);

        $this->assertArrayHasKey('widgets', $summaryWidget);
        //#$this->assertPolicySummaryHaveKeys($summaryWidget['widget'][0]);
        $this->assertPolicySummaryHasStarlingWidgetKeys($summaryWidget['widgets'][0]);
    }

    #public function testTwoPolicySummary()
    #{
    #    $rewardPotValue = 11.97;
    #    $user = $this->generateUserWithTwoPolicies($rewardPotValue);
    #
    #    /** @var UserPolicySummary $userPolicySummary */
    #    $userPolicySummary = self::$container->get('test.App\Normalizer\UserPolicySummary');
    #
    #    $summary = $userPolicySummary->shortPolicySummary($user);
    #    $this->assertSummaryMatchesUserWithTwoPolicies($summary, $rewardPotValue);
    #}

    /**
     * Make a user, with one cancelled policy + a good one
     */
    private function generateUserWithLiveAndCancelledPolicies(float $rewardPotValue = 0): stdClass
    {
        $email = self::generateEmail('testUser'.random_int(1, 1e7), $this);
        $password = 'foo';
        $inactivePhone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $inactivePhone,
            self::$dm
        );
        $policy1 = self::initPolicy($user, self::$dm, $inactivePhone, null, true, true);
        $policy1->setStatus(Policy::STATUS_CANCELLED);
        self::$dm->flush();

        $this->assertTrue($policy1->getUser()->hasPolicy(), 'expected to have a (cancelled) policy');
        $this->assertFalse($policy1->getUser()->hasActivePolicy(), 'expected policy to not be active');

        $activePhone = self::getRandomPhone(self::$dm);
        $policy2 = self::initPolicy($user, self::$dm, $activePhone, null, true, true);
        $policy2->setStatus(Policy::STATUS_ACTIVE);
        $policy2->setPotValue($rewardPotValue);

        self::$dm->flush();

        $this->assertTrue($policy2->getUser()->hasActivePolicy(), 'expected 2nd policy to be active');

        $output = new stdClass();
        $output->user = $user;
        $output->inactivePolicy = $policy1;    // policy has been cancelled
        $output->inactivePhone = $inactivePhone;
        $output->activePolicy = $policy2;
        $output->activePhone = $activePhone;

        return $output;
    }

    private function assertPolicySummaryHasStarlingWidgetKeys(array $widget)
    {
        $this->assertArrayHasKey('type', $widget);
        $this->assertSame('TEXT', $widget['type']);
        $this->assertArrayHasKey('title', $widget);
        $this->assertArrayHasKey('text', $widget);
        $this->assertArrayHasKey('launchUrl', $widget);
    }
}
