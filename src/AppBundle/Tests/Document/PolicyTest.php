<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\DateTrait;
use AppBundle\Classes\SoSure;

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
        $policy = new PhonePolicy();
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
        $policy = new PhonePolicy();
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
        $policy = new PhonePolicy();
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
        $policy = new PhonePolicy();
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
        $a = new PhonePolicy();
        $a->setId("1");
        $a->setPolicyNumber("Mob/2016/1");
        $a->setPremium($premium);
        $a->setStart(new \DateTime('2017-01-01'));
        $a->setEnd(new \DateTime('2017-12-30'));
        $a->setStatus(Policy::STATUS_CANCELLED);
        $a->setCancelledReason(Policy::CANCELLED_UNPAID);
        $b = new PhonePolicy();
        $b->setPolicyNumber("Mob/2016/2");
        $b->setId("2");
        $b->setPremium($premium);
        $b->setStart(new \DateTime('2017-10-15'));
        $b->setEnd(new \DateTime('2018-05-02 14:05'));
        $b->setStatus(Policy::STATUS_CANCELLED);
        $b->setCancelledReason(Policy::CANCELLED_UPGRADE);
        $c = new PhonePolicy();
        $c->setId("3");
        $c->setPolicyNumber("Mob/2016/3");
        $c->setPremium($premium);
        $c->setStart(new \DateTime('2018-05-02 20:21'));
        $c->setStatus(Policy::STATUS_ACTIVE);
        $d = new PhonePolicy();
        $d->setId("4");
        $d->setPolicyNumber("Mob/2016/4");
        $d->setPremium($premium);
        $d->setStart(new \DateTime('2018-05-02 20:21'));
        $d->setEnd(new \DateTime('2018-09-09 12:30'));
        $d->setStatus(Policy::STATUS_CANCELLED);
        $d->setCancelledReason(Policy::CANCELLED_UPGRADE);
        $e = new PhonePolicy();
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
        $policy = new PhonePolicy();
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
        // make sure the date is indeed 30 days after the missing payment.
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
        $policy = new PhonePolicy();
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
}
