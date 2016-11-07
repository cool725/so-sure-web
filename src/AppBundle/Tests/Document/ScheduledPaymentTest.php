<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\SalvaPhonePolicy;
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
}
