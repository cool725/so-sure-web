<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Tests\Create;
use AppBundle\Classes\SoSure;
use AppBundle\Classes\Helvetia;

/**
 * Tests the behaviour of the policy document.
 * @group unit
 */
class PolicyTest extends \PHPUnit\Framework\TestCase
{
    use DateTrait;

    /**
     * Tests if the get scheduled payment refunds method works correctly.
     */
    public function testGetScheduledPaymentRefunds()
    {
        // Set up the data.
        $nNonRefunds = rand(5, 50);
        $nRefunds = rand(5, 50);
        $nonRefundAmount = rand(0, 100) / 90;
        $refundAmount = rand(0, 100) / 90;
        $date = new \DateTime();
        $policy = new HelvetiaPhonePolicy();
        for ($i = 0; $i < $nNonRefunds; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setType(ScheduledPayment::TYPE_SCHEDULED);
            $scheduledPayment->setAmount($nonRefundAmount);
            $scheduledPayment->setScheduled($this->addDays($date, rand(-50, 50)));
            $policy->addScheduledPayment($scheduledPayment);
        }
        $refunds = [];
        for ($i = 0; $i < $nRefunds; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setType(ScheduledPayment::TYPE_REFUND);
            $scheduledPayment->setAmount($refundAmount);
            $scheduledPayment->setScheduled($this->addDays($date, rand(0, 50)));
            $policy->addScheduledPayment($scheduledPayment);
            $refunds[] = $scheduledPayment;
        }
        // now see
        $foundRefunds = $policy->getScheduledPaymentRefunds();
        foreach ($foundRefunds as $refund) {
            $this->assertContains($refund, $refunds);
        }
        $this->assertEquals($nRefunds, count($foundRefunds));
    }

    /**
     * Tests if the get scheduled payment refunds method works correctly.
     */
    public function testGetScheduledPaymentRefundAmount()
    {
        // Set up the data.
        $nNonRefunds = rand(5, 50);
        $nRefunds = rand(5, 50);
        $nonRefundAmount = rand(0, 100) / 90;
        $refundAmount = rand(-100, -1) / 90;
        $date = new \DateTime();
        $policy = new HelvetiaPhonePolicy();
        for ($i = 0; $i < $nNonRefunds; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setType(ScheduledPayment::TYPE_SCHEDULED);
            $scheduledPayment->setAmount($nonRefundAmount);
            $scheduledPayment->setScheduled($this->addDays($date, rand(-50, 50)));
            $policy->addScheduledPayment($scheduledPayment);
        }
        for ($i = 0; $i < $nRefunds; $i++) {
            $scheduledPayment = new ScheduledPayment();
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $scheduledPayment->setType(ScheduledPayment::TYPE_REFUND);
            $scheduledPayment->setAmount($refundAmount);
            $scheduledPayment->setScheduled($this->addDays($date, rand(1, 50)));
            $policy->addScheduledPayment($scheduledPayment);
        }
        // Now check if it works.
        $this->assertEquals(abs($nRefunds * $refundAmount), $policy->getScheduledPaymentRefundAmount());
    }

    /**
     * Tests to make sure that get last reverted scheduled payment works correctly when there is normal data.
     */
    public function testGetLastRevertedScheduledPaymentNormal()
    {
        $policy = new HelvetiaPhonePolicy();
        $premium = new PhonePremium();
        $premium->setGwp(5.3);
        $premium->setIpt(1.2);
        $policy->setPremium($premium);
        $startDate = new \DateTime();
        $date = clone $startDate;
        for ($i = 0; $i < 5; $i++) {
            $payment = new ScheduledPayment();
            $payment->setAmount($premium->getMonthlyPremiumPrice());
            $payment->setScheduled($date);
            $payment->setStatus(ScheduledPayment::STATUS_SUCCESS);
            $policy->addScheduledPayment($payment);
            $date = clone $date;
            $date->add(new \DateInterval("P1M"));
        }
        $revertedPayment = new ScheduledPayment();
        $revertedPayment->setAmount($premium->getMonthlyPremiumPrice());
        $revertedPayment->setScheduled($date);
        $revertedPayment->setStatus(ScheduledPayment::STATUS_REVERTED);
        $policy->addScheduledPayment($revertedPayment);
        $date = clone $date;
        $date->add(new \DateInterval("P1M"));
        for ($i = 0; $i < 3; $i++) {
            $payment = new ScheduledPayment();
            $payment->setAmount($premium->getMonthlyPremiumPrice());
            $payment->setScheduled($date);
            $payment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
            $policy->addScheduledPayment($payment);
            $date = clone $date;
            $date->add(new \DateInterval("P1M"));
        }
        // now get the reverted scheduled payment.
        $foundRevertedPayment = $policy->getLastRevertedScheduledPayment();
        $this->assertEquals($revertedPayment, $foundRevertedPayment);
    }

    /**
     * Tests to make sure that get last reverted scheduled payment works correctly when there are no scheduled payments.
     */
    public function testGetLastRevertedScheduledPaymentEmpty()
    {
        $policy = new HelvetiaPhonePolicy();
        $premium = new PhonePremium();
        $premium->setGwp(5.3);
        $premium->setIpt(1.2);
        $policy->setPremium($premium);
        // now try to get the reverted scheduled payment but actually it does not exist.
        $this->assertNull($policy->getLastRevertedScheduledPayment());
        // now do it again with schedule but no revert.
        $startDate = new \DateTime();
        $date = clone $startDate;
        for ($i = 0; $i < 5; $i++) {
            $payment = new ScheduledPayment();
            $payment->setAmount($premium->getMonthlyPremiumPrice());
            $payment->setScheduled($date);
            $payment->setStatus(ScheduledPayment::STATUS_SUCCESS);
            $policy->addScheduledPayment($payment);
            $date = clone $date;
            $date->add(new \DateInterval("P1M"));
        }
        // try to to get nonexistent reverted scheduled payment.
        $foundRevertedPayment = $policy->getLastRevertedScheduledPayment();
        $this->assertNull($foundRevertedPayment);
    }

    /**
     * Tests to make sure that getUpgradedFrom can accurately detect upgrades without over reporting them even when
     * dates are close together enough that there is some ambigiuity.
     */
    public function testGetUpgradedFrom()
    {
        $user = new User();
        $premium = new PhonePremium();
        $a = new HelvetiaPhonePolicy();
        $a->setId("1");
        $a->setPolicyNumber("Mob/2016/1");
        $a->setPremium($premium);
        $a->setStart(new \DateTime('2017-01-01'));
        $a->setEnd(new \DateTime('2017-12-30'));
        $a->setStatus(Policy::STATUS_CANCELLED);
        $a->setCancelledReason(Policy::CANCELLED_UNPAID);
        $b = new HelvetiaPhonePolicy();
        $b->setPolicyNumber("Mob/2016/2");
        $b->setId("2");
        $b->setPremium($premium);
        $b->setStart(new \DateTime('2017-10-15'));
        $b->setEnd(new \DateTime('2018-05-02 14:05'));
        $b->setStatus(Policy::STATUS_CANCELLED);
        $b->setCancelledReason(Policy::CANCELLED_UPGRADE);
        $c = new HelvetiaPhonePolicy();
        $c->setId("3");
        $c->setPolicyNumber("Mob/2016/3");
        $c->setPremium($premium);
        $c->setStart(new \DateTime('2018-05-02 20:21'));
        $c->setStatus(Policy::STATUS_ACTIVE);
        $d = new HelvetiaPhonePolicy();
        $d->setId("4");
        $d->setPolicyNumber("Mob/2016/4");
        $d->setPremium($premium);
        $d->setStart(new \DateTime('2018-05-02 20:21'));
        $d->setEnd(new \DateTime('2018-09-09 12:30'));
        $d->setStatus(Policy::STATUS_CANCELLED);
        $d->setCancelledReason(Policy::CANCELLED_UPGRADE);
        $e = new HelvetiaPhonePolicy();
        $e->setId("5");
        $e->setPolicyNumber("Mob/2016/5");
        $e->setPremium($premium);
        $e->setStart(new \DateTime('2018-09-10 9:45'));
        $e->setStatus(Policy::STATUS_ACTIVE);
        $user->addPolicy($b);
        $user->addPolicy($c);
        $user->addPolicy($a);
        $user->addPolicy($d);
        $user->addPolicy($e);
        // now check each one reports what it should.
        $this->assertNull($a->getUpgradedFrom());
        $this->assertNull($b->getUpgradedFrom());
        $this->assertNull($c->getUpgradedFrom());
        $this->assertEquals($b, $d->getUpgradedFrom());
        $this->assertEquals($d, $e->getUpgradedFrom());
        // TODO: this function must be updated to allow upgrade created before cancellation as well as after.
    }

    /**
     * Makes sure that get policy expiry date gives the right date and does not crash in weird circumstances or
     * anything of that nature.
     */
    public function testGetPolicyExpiryDate()
    {
        $premium = new PhonePremium();
        $premium->setGwp(12.34);
        $premium->setIpt(0.66);
        $date = new \DateTime();
        $policy = new HelvetiaPhonePolicy();
        $policy->setPremium($premium);
        $policy->setStart(clone $date);
        $policy->setEnd((clone $date)->add(new \DateInterval("P1Y")));
        $policy->setBilling(clone $date);
        $policy->setStatus(Policy::STATUS_UNPAID);
        for ($i = 0; $i < 3; $i++) {
            $payment = new CheckoutPayment();
            $payment->setAmount($premium->getMonthlyPremiumPrice());
            $payment->setSuccess(true);
            $payment->setDate((clone $date)->add(new \DateInterval("P{$i}M")));
            $policy->addPayment($payment);
        }
        // Make sure the date is indeed 30 days after the missing payment.
        $this->assertEquals(
            (clone $date)->add(new \DateInterval("P3M30D"))->format("YMd"),
            $policy->getPolicyExpirationDate((clone $date)->add(new \DateInterval("P3M")))->format("YMd")
        );
        // Add a refund and it should be a month sooner.
        $refund = new CheckoutPayment();
        $refund->setAmount(0 - $premium->getMonthlyPremiumPrice());
        $refund->setSuccess(true);
        $policy->addPayment($refund);
        $this->assertEquals(
            (clone $date)->add(new \DateInterval("P2M30D"))->format("YMd"),
            $policy->getPolicyExpirationDate((clone $date)->add(new \DateInterval("P3M")))->format("YMd")
        );
    }

    /**
     * We shall add a lot of refunds and then test getting the expiry date and make sure it does not crash or behave
     * strangely.
     */
    public function testGetPolicyExpiryDateWithHeavyRefunds()
    {
        $premium = new PhonePremium();
        $premium->setGwp(2.34);
        $premium->setIpt(0.66);
        $date = new \DateTime();
        $policy = new HelvetiaPhonePolicy();
        $policy->setPremium($premium);
        $policy->setStart(clone $date);
        $policy->setEnd((clone $date)->add(new \DateInterval("P1Y")));
        $policy->setBilling(clone $date);
        $policy->setStatus(Policy::STATUS_UNPAID);
        for ($i = 0; $i < 8; $i++) {
            $payment = new CheckoutPayment();
            $payment->setAmount(0 - $premium->getMonthlyPremiumPrice());
            $payment->setSuccess(true);
            $payment->setDate(clone $date);
            $policy->addPayment($payment);
        }
        // make sure the date is indeed 30 days after the missing payment.
        $this->assertEquals(
            $this->startOfDay((clone $date)->add(new \DateInterval("P30D"))),
            $this->startOfDay($policy->getPolicyExpirationDate($date))
        );
    }


    public function testGetNonRewardConnections()
    {
        //Create Policy
        $policy = new HelvetiaPhonePolicy();
        $user = new User();
        $policy->setUser($user);

        //Create Connections
        $rewardConnection = new RewardConnection();
        $rewardConnection->setLinkedUser($user);
        $standardConnection = new StandardConnection();
        $standardConnection->setLinkedUser($user);
        $renewalConnection = new RenewalConnection();
        $renewalConnection->setLinkedUser($user);

        //Influencer Connection
        $influencerConnection = new RewardConnection();
        $influencer = new User();
        $influencer->setIsInfluencer(true);
        $influencerConnection->setLinkedUser($influencer);

        $policy->addConnection($rewardConnection);
        $policy->addConnection($standardConnection);
        $policy->addConnection($renewalConnection);
        $policy->addConnection($influencerConnection);

        //Get Connections
        $connections = $policy->getConnections();
        $this->assertEquals(count($connections), 4);

        //Get Standard Connections
        $connections = $policy->getStandardConnections();
        $this->assertEquals(count($connections), 1);

        //Get Non Reward Connections, incuding influencers
        $connections = $policy->getNonRewardConnections();
        $this->assertEquals(count($connections), 3);
        foreach ($connections as $connection) {
            $this->assertTrue(
                $connection instanceof StandardConnection ||
                $connection instanceof RenewalConnection ||
                ($connection instanceof RewardConnection && $connection->getLinkedUser()->getIsInfluencer())
            );
        }
    }

    /**
     * Tests to make sure that refund commission is correctly calculated.
     */
    public function testRefundCommission()
    {
        $terms = new PolicyTerms();
        $terms->setVersion('Version 11 January 2019');
        $user = Create::user();
        $a = Create::policy($user, '2020-01-01', Policy::STATUS_CANCELLED, 12);
        $b = Create::policy($user, '2020-01-01', Policy::STATUS_CANCELLED, 12);
        $c = Create::policy($user, '2020-01-01', Policy::STATUS_CANCELLED, 12);
        $a->setPolicyTerms($terms);
        $b->setPolicyTerms($terms);
        $c->setPolicyTerms($terms);
        $a->setCancelledReason(Policy::CANCELLED_COOLOFF);
        $b->setCancelledReason(Policy::CANCELLED_USER_REQUESTED);
        $c->setCancelledReason(Policy::CANCELLED_UPGRADE);
        $a->setEnd(new \DateTime('2020-01-19'));
        $b->setEnd(new \DateTime('2020-04-06'));
        $c->setEnd(new \DateTime('2020-09-16'));
        Create::standardPayment($a, '2020-01-01', true);
        Create::standardPayment($b, '2020-01-03', true);
        Create::standardPayment($b, '2020-02-01', true);
        Create::standardPayment($b, '2020-03-01', true);
        Create::standardPayment($b, '2020-04-01', true);
        Create::standardPayment($c, '2020-01-01', true);
        Create::standardPayment($c, '2020-02-02', true);
        Create::standardPayment($c, '2020-03-01', true);
        Create::standardPayment($c, '2020-04-01', true);
        Create::standardPayment($c, '2020-05-01', true);
        Create::standardPayment($c, '2020-06-07', true);
        Create::standardPayment($c, '2020-07-01', false);
        Create::standardPayment($c, '2020-08-03', true);
        Create::standardPayment($c, '2020-09-01', true);
        // For A should be the full commission paid which is 20% of the premium paid minum the 5p broker commission.
        $this->assertEquals(
            $a->getRefundCoverholderCommissionAmount(),
            $a->getPremium()->getGwp() / 5,
            null,
            0.01
        );
        $this->assertEquals($a->getRefundBrokerCommissionAmount(), Helvetia::MONTHLY_BROKER_COMMISSION, null, 0.01);
        // For B should be the total commission paid - the commission owed pro rata which should be positive.
        $this->assertEquals(
            $b->getRefundCoverholderCommissionAmount(),
            $b->getCoverholderCommissionPaid() - ($b->getPremium()->getGwp() * 12 / 5) * 97 / 366,
            null,
            0.01
        );
        $this->assertEquals(
            $b->getRefundBrokerCommissionAmount(),
            (Helvetia::MONTHLY_BROKER_COMMISSION * 4) - Helvetia::YEARLY_BROKER_COMMISSION * 97 / 366,
            null,
            0.01
        );
        $this->assertTrue($b->getRefundCoverholderCommissionAmount() > 0);
        $this->assertTrue($b->getRefundBrokerCommissionAmount() > 0);
        // For C should be the total commission paid - the commission owed pro rata which should be negative.
        $this->assertEquals(
            $c->getRefundCoverholderCommissionAmount(),
            $c->getCoverholderCommissionPaid() - ($c->getPremium()->getGwp() * 12 / 5) * 260 / 366,
            null,
            0.01
        );
        $this->assertEquals(
            $c->getRefundBrokerCommissionAmount(),
            (Helvetia::MONTHLY_BROKER_COMMISSION * 8) - Helvetia::YEARLY_BROKER_COMMISSION * 260 / 366,
            null,
            0.01
        );
        $this->assertTrue($c->getRefundCoverholderCommissionAmount() < 0);
        $this->assertTrue($c->getRefundBrokerCommissionAmount() < 0);
    }
}
