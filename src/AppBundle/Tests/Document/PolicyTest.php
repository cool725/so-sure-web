<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\DateTrait;

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
        $this->assertEquals($revertedPayment, $policy->getLastRevertedScheduledPayment());
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
        $this->assertNull($policy->getLastRevertedScheduledPayment());
    }
}
