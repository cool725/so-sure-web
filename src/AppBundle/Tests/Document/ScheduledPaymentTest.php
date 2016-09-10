<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\ScheduledPayment;

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
}
