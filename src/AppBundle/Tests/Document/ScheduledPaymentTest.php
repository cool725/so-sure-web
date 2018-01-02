<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\User;

/**
 * @group unit
 */
class ScheduledPaymentTest extends \PHPUnit_Framework_TestCase
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

        $scheduledPayment->getScheduled()->setTimezone(new \DateTimeZone('UTC'));
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
}
