<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\User;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Payment\JudoPayment;

/**
 * @group unit
 */
class ScheduledPaymentTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testCanBeRun()
    {
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setScheduled(new \DateTime('2016-01-01 01:00'));
        $this->assertFalse($scheduledPayment->canBeRun(new \DateTime('2016-01-01')));
        $this->assertTrue($scheduledPayment->canBeRun(new \DateTime('2016-01-01 01:00')));
        $this->assertTrue($scheduledPayment->canBeRun(new \DateTime('2016-01-01 02:00')));
    }

    public function testCancel()
    {
        $scheduledPayment = new ScheduledPayment();
        $this->assertNull($scheduledPayment->getStatus());
        $scheduledPayment->cancel();
        $this->assertEquals(ScheduledPayment::STATUS_CANCELLED, $scheduledPayment->getStatus());
    }

    public function testIsBillable()
    {
        $premium = new PhonePremium();
        $policy = new SalvaPhonePolicy();
        $policy->setPremium($premium);
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        $this->assertTrue($policy->isBillablePolicy());

        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setType(ScheduledPayment::TYPE_SCHEDULED);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $policy->addScheduledPayment($scheduledPayment);

        $this->assertTrue($scheduledPayment->isBillable());

        $scheduledPayment->setStatus(ScheduledPayment::STATUS_CANCELLED);
        $this->assertFalse($scheduledPayment->isBillable());

        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $this->assertFalse($scheduledPayment->isBillable());

        $scheduledPayment->setType(ScheduledPayment::TYPE_ADMIN);
        $this->assertTrue($scheduledPayment->isBillable());

        $scheduledPayment->setStatus(ScheduledPayment::STATUS_CANCELLED);
        $this->assertFalse($scheduledPayment->isBillable());
    }

    public function testHasCorrectBillingDay()
    {
        $premium = new PhonePremium();
        $policy = new SalvaPhonePolicy();
        $policy->setBilling(new \DateTime('2017-01-15 15:00'));

        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setType(ScheduledPayment::TYPE_SCHEDULED);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduledPayment->setScheduled(new \DateTime('2017-06-15 12:00'));
        $policy->addScheduledPayment($scheduledPayment);

        $this->assertTrue($scheduledPayment->hasCorrectBillingDay());

        $scheduledPayment->setScheduled(new \DateTime('2017-06-16 12:00'));
        $this->assertFalse($scheduledPayment->hasCorrectBillingDay());

        $scheduledPayment->setType(ScheduledPayment::TYPE_RESCHEDULED);
        $this->assertNull($scheduledPayment->hasCorrectBillingDay());

        $scheduledPayment->setType(ScheduledPayment::TYPE_SCHEDULED);
        $scheduledPayment->setScheduled(new \DateTime('2017-06-15 00:00', new \DateTimeZone('Europe/London')));
        $this->assertTrue($scheduledPayment->hasCorrectBillingDay());

        if ($scheduledPayment->getScheduled()) {
            $scheduledPayment->getScheduled()->setTimezone(new \DateTimeZone('UTC'));
        }
        $this->assertTrue($scheduledPayment->hasCorrectBillingDay());
    }

    public function testHasCorrectBillingDayHackOneHour()
    {
        $policy2 = new SalvaPhonePolicy();
        $policy2->setBilling(new \DateTime('2017-06-15 23:00', new \DateTimeZone('Europe/London')));

        $scheduledPayment2 = new ScheduledPayment();
        $scheduledPayment2->setType(ScheduledPayment::TYPE_SCHEDULED);
        $scheduledPayment2->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduledPayment2->setScheduled(new \DateTime('2017-06-16 00:00', new \DateTimeZone('Europe/London')));
        $policy2->addScheduledPayment($scheduledPayment2);
        $this->assertTrue($scheduledPayment2->hasCorrectBillingDay());
    }

    public function testReschedule()
    {
        $premium = new PhonePremium();
        $policy = new SalvaPhonePolicy();
        $policy->setBilling(new \DateTime('2017-01-15 15:00'));
        $policy->setPaymentMethod(new JudoPaymentMethod());

        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setType(ScheduledPayment::TYPE_SCHEDULED);
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduledPayment->setScheduled(new \DateTime('2017-06-15 12:00'));
        $policy->addScheduledPayment($scheduledPayment);

        $rescheduled = $scheduledPayment->reschedule(new \DateTime('2017-06-15 12:00'));
        $this->assertEquals($scheduledPayment->getAmount(), $rescheduled->getAmount());
        $this->assertEquals($scheduledPayment::STATUS_SCHEDULED, $rescheduled->getStatus());
        $this->assertEquals(ScheduledPayment::TYPE_RESCHEDULED, $rescheduled->getType());
        $this->assertEquals(new \DateTime('2017-06-22 12:00'), $rescheduled->getScheduled());

        $rescheduled = $scheduledPayment->reschedule(new \DateTime('2017-06-15 12:00'), 0);
        $this->assertEquals($scheduledPayment->getAmount(), $rescheduled->getAmount());
        $this->assertEquals($scheduledPayment::STATUS_SCHEDULED, $rescheduled->getStatus());
        $this->assertEquals(ScheduledPayment::TYPE_RESCHEDULED, $rescheduled->getType());
        $this->assertEquals(new \DateTime('2017-06-15 12:00'), $rescheduled->getScheduled());
    }

    public function testValidateRunable()
    {
        $user = new User();
        $premium = new PhonePremium();
        $policy = new SalvaPhonePolicy();
        $policy->setPaymentMethod(new JudoPaymentMethod());
        $policy->setPolicyNumber(sprintf('TESTING/%s', rand(1, 999999)));
        $policy->setBilling(new \DateTime('2017-01-15 15:00'));
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        $policy->setPremium($premium);
        $user->addPolicy($policy);
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduledPayment->setScheduled(new \DateTime('2016-01-01 01:00'));
        $policy->addScheduledPayment($scheduledPayment);

        $scheduledPayment->validateRunable('TESTING', new \DateTime('2016-01-01 02:00'));

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    /**
     * @expectedException \Exception
     */
    public function testValidateRunablePaymentReceived()
    {
        $user = new User();
        $premium = new PhonePremium();
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyNumber(sprintf('TESTING/%s', rand(1, 999999)));
        $policy->setBilling(new \DateTime('2017-01-15 15:00'));
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        $policy->setPremium($premium);
        $user->addPolicy($policy);
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduledPayment->setScheduled(new \DateTime('2016-01-01 01:00'));
        $payment = new JudoPayment();
        $payment->setSuccess(true);
        $scheduledPayment->setPayment($payment);
        $policy->addScheduledPayment($scheduledPayment);

        $scheduledPayment->validateRunable('TESTING', new \DateTime('2016-01-01 02:00'));
    }

    /**
     * @expectedException \Exception
     */
    public function testValidateRunableCanBeRun()
    {
        $user = new User();
        $premium = new PhonePremium();
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyNumber(sprintf('TESTING/%s', rand(1, 999999)));
        $policy->setBilling(new \DateTime('2017-01-15 15:00'));
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        $policy->setPremium($premium);
        $user->addPolicy($policy);
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduledPayment->setScheduled(new \DateTime('2016-01-01 01:00'));
        $payment = new JudoPayment();
        $payment->setSuccess(true);
        $scheduledPayment->setPayment($payment);
        $policy->addScheduledPayment($scheduledPayment);

        $scheduledPayment->validateRunable('TESTING', new \DateTime('2016-01-01 00:00'));
    }

    /**
     * @expectedException \Exception
     */
    public function testValidateRunableCancelled()
    {
        $user = new User();
        $premium = new PhonePremium();
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyNumber(sprintf('TESTING/%s', rand(1, 999999)));
        $policy->setBilling(new \DateTime('2017-01-15 15:00'));
        $policy->setStatus(SalvaPhonePolicy::STATUS_CANCELLED);
        $policy->setPremium($premium);
        $user->addPolicy($policy);
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduledPayment->setScheduled(new \DateTime('2016-01-01 01:00'));
        $payment = new JudoPayment();
        $payment->setSuccess(true);
        $scheduledPayment->setPayment($payment);
        $policy->addScheduledPayment($scheduledPayment);

        $scheduledPayment->validateRunable('TESTING', new \DateTime('2016-01-01 00:00'));
    }

    /**
     * @expectedException \Exception
     */
    public function testValidateRunableValidPolicy()
    {
        $user = new User();
        $premium = new PhonePremium();
        $policy = new SalvaPhonePolicy();
        $policy->setPolicyNumber(sprintf('TEST/%s', rand(1, 999999)));
        $policy->setBilling(new \DateTime('2017-01-15 15:00'));
        $policy->setStatus(SalvaPhonePolicy::STATUS_ACTIVE);
        $policy->setPremium($premium);
        $user->addPolicy($policy);
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);
        $scheduledPayment->setScheduled(new \DateTime('2016-01-01 01:00'));
        $payment = new JudoPayment();
        $payment->setSuccess(true);
        $scheduledPayment->setPayment($payment);
        $policy->addScheduledPayment($scheduledPayment);

        $scheduledPayment->validateRunable('TESTING', new \DateTime('2016-01-01 00:00'));
    }
}
