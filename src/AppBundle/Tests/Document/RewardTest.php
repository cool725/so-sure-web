<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Claim;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Reward;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\DateTrait;

/**
 * Proves the behaviour of the reward document.
 * @group unit
 */
class RewardTest extends \PHPUnit\Framework\TestCase
{
    use DateTrait;

    /**
     * Tests that Reward::isOpen behaves correctly.
     * If a reward has a usage limit and that limit has been hit it should be closed, also, when it has an expiration
     * date and that expiration date has been reached it should be closed. In all other cases it should be open.
     * @param boolean   $result     is the expected result of Reward::isOpen.
     * @param \DateTime $date       is the date at which to check the result.
     * @param int       $usage      is the number of connections the reward should have at the time of checking.
     * @param \DateTime $expiration is the expiry date to give to the reward.
     * @param int       $usageLimit is the usage limit to give to the reward.
     * @dataProvider isOpenProvider
     */
    public function testIsOpen($result, $date, $usage, $expiration = null, $usageLimit = null)
    {
        $reward = new Reward();
        if ($expiration) {
            $reward->setExpiryDate($expiration);
        }
        if ($usageLimit) {
            $reward->setUsageLimit($usageLimit);
        }
        for ($i = 0; $i < $usage; $i++) {
            $reward->addConnection(new Connection());
        }
        $this->assertEquals($result, $reward->isOpen($date));
    }

    /**
     * Covers all cases for Reward::isOpen
     */
    public static function isOpenProvider()
    {
        $date = new \DateTime();
        $inExpiry = (new \DateTime())->sub(new \DateInterval("P5D"));
        $outExpiry = (new \DateTime())->add(new \DateInterval("P5D"));
        return [
            // I forget
            [true, $date, rand(1, 50)],
            // I forget
            [true, $date, 50, null, 60],
            // I forget
            [false, $date, 50, null, 40],
            // I forget
            [true, $inExpiry, rand(1, 50), $date],
            // I forget
            [true, $inExpiry, 40, $date, 50],
            // I forget
            [false, $inExpiry, 50, $date, 40],
            // I forget
            [false, $outExpiry, 50, $date],
            // I forget
            [false, $outExpiry, 50, $date, 60],
            // I forget
            [false, $outExpiry, 50, $date, 40],
        ];
    }


    /**
     * Tests that a reward can not be applied when it is not open.
     */
    public function testCanApplyNotOpen()
    {
        $user = new User();
        $policy = $this->addPolicyToUser($user, new \DateTime());
        $reward = new Reward();
        $reward->setExpiryDate((new \DateTime())->sub(new \DateInterval("P3D")));
        $this->assertFalse($reward->canApply($policy, new \DateTime()));
    }

    /**
     * Tests that Reward::canApply behaves correctly.
     * If the reward is not open then it will not apply, if there are age limits then it will enforce them, if there
     * are checks on claims, renewal, or cancellations then will be enforced too.
     * @param boolean   $result        whether it should be allowed or disallowed in the given case.
     * @param boolean   $claimPolicy   whether to put a claim on the test policy.
     * @param boolean   $renewalPolicy whether to make the test policy a renewal.
     * @param boolean   $cancelPolicy  whether to make the test policy owner have a previous cancellation.
     * @param \DateTime $policyStart   the date at which the test policy should be set to have started.
     * @param int       $minAge        is the minimumum age a policy needs to use the reward.
     * @param int       $maxAge        is the maximum age a policy can have to use the reward.
     * @param boolean   $notClaimed    is whether or not a user can claim and then use the reward.
     * @param boolean   $renewed       is whether a user can use the reward without a renewed policy.
     * @param boolean   $cancelled     is whether a user can use the reward without a cancelled policy.
     * @dataProvider canApplyProvider
     */
    public function testCanApply(
        $result,
        $claimPolicy,
        $renewalPolicy,
        $cancelPolicy,
        $policyStart,
        $minAge = null,
        $maxAge = null,
        $notClaimed = null,
        $renewed = null,
        $cancelled = null
    ) {
        $user = new User();
        $policy = $this->addPolicyToUser($user, $policyStart);
        if ($claimPolicy) {
            $policy->addClaim(new Claim());
        }
        if ($renewalPolicy) {
            $oldPolicy = $this->addPolicyToUser($user, (clone $policyStart)->sub(new \DateInterval("P1Y")));
            $oldPolicy->setStatus(Policy::STATUS_EXPIRED);
            $oldPolicy->link($policy);
        }
        if ($cancelPolicy) {
            $oldPolicy = $this->addPolicyToUser($user, (clone $policyStart)->sub(new \DateInterval("P1Y")));
            $oldPolicy->setStatus(Policy::STATUS_CANCELLED);
        }
        $reward = new Reward();
        if ($minAge) {
            $reward->setPolicyAgeMin($minAge);
        }
        if ($maxAge) {
            $reward->setPolicyAgeMax($maxAge);
        }
        if ($notClaimed) {
            $reward->setHasNotClaimed($notClaimed);
        }
        if ($renewed) {
            $reward->setHasRenewed($renewed);
        }
        if ($cancelled) {
            $reward->setHasCancelled($cancelled);
        }
        $this->assertEquals($result, $reward->canApply($policy, new \DateTime()));
    }

    /**
     * Covers all cases for Reward::canApply except for ones relating to whether or not the reward is open.
     */
    public static function canApplyProvider()
    {
        $date = new \DateTime();
        $old = (new \DateTime())->sub(new \DateInterval("P50D"));
        return [
            // too young failure
            [false, false, false, false, $date, 1],
            // too old failure
            [false, false, false, false, $old, null, 30],
            // no claims failure
            [false, true, false, false, $date, null, null, true],
            // renew failure
            [false, false, false, false, $date, null, null, false, true],
            // cancel failure
            [false, false, false, false, $date, null, null, false, false, true],
            // age success
            [true, false, false, false, $old, 40, 60],
            // no claim success
            [true, false, false, false, $date, null, null, true, false, false],
            // renew success
            [true, false, true, false, $date, null, null, false, true, false],
            // cancel success
            [true, false, false, true, $date, null, null, false, false, true],
            // no conditions success
            [true, true, false, false, $date, null, null, false, false, false],
            // no claim and renew success
            [true, false, true, false, $date, null, null, true, true, false],
            // no claim and renew and cancel success
            [true, false, true, true, $date, null, null, true, true, true],
            // renew and cancel success
            [true, true, true, true, $date, null, null, false, true, true],
            // no claim and cancel success
            [true, false, false, true, $date, null, null, true, false, true],
            // no claim and cancel failure due to claim
            [false, true, false, true, $date, null, null, true, false, true],
            // no claim and cancel and renew failure due to no cancel
            [false, false, true, false, $date, null, null, true, true, true],
            // no claim and cancel and renew failure due to age.
            [false, false, true, true, $date, 2, 20, true, true, true]
        ];
    }

    /**
     * Creates a new policy and adds it to the given user.
     * @param User $user is the user to add the policy to.
     * @param \DateTime $policyStart is the date at which the policy starts.
     * @return Policy the created policy.
     */
    private function addPolicyToUser($user, $policyStart)
    {
        $policy = new PhonePolicy();
        $policy->setStart($policyStart);
        $user->addPolicy($policy);
        return $policy;
    }

}

