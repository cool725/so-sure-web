<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\DateTrait;

/**
 * @group unit
 */
class DateTraitTest extends \PHPUnit\Framework\TestCase
{
    use DateTrait;

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testStartOfPreviousMonth()
    {
        $this->assertEquals(
            new \DateTime('2016-01-01 00:00'),
            $this->startOfPreviousMonth(new \DateTime('2016-02-12 15:00'))
        );
    }

    public function testStartOfMonth()
    {
        $this->assertEquals(
            new \DateTime('2016-02-01 00:00'),
            $this->startOfMonth(new \DateTime('2016-02-12 15:00'))
        );
    }

    public function testEndOfMonth()
    {
        $this->assertEquals(
            new \DateTime('2016-03-01 00:00'),
            $this->endOfMonth(new \DateTime('2016-02-12 15:00'))
        );
    }

    public function testStartOfDay()
    {
        $this->assertEquals(
            new \DateTime('2016-02-12 00:00'),
            $this->startOfDay(new \DateTime('2016-02-12 15:00'))
        );
        $this->assertEquals(
            new \DateTime('2016-06-12 00:00', new \DateTimeZone('Europe/London')),
            $this->startOfDay(new \DateTime('2016-06-12 15:00', new \DateTimeZone('Europe/London')))
        );
    }

    public function testEndOfDay()
    {
        $this->assertEquals(
            new \DateTime('2016-02-13 00:00'),
            $this->endOfDay(new \DateTime('2016-02-12 15:00'))
        );
        $this->assertEquals(
            new \DateTime('2016-06-13 00:00', new \DateTimeZone('Europe/London')),
            $this->endOfDay(new \DateTime('2016-06-12 15:00', new \DateTimeZone('Europe/London')))
        );
    }

    public function testAddBusinessDays()
    {
        // mon
        $this->assertEquals(
            new \DateTime('2016-12-07 00:00'),
            $this->addBusinessDays(new \DateTime('2016-12-05 00:00'), 2)
        );

        // fri
        $this->assertEquals(
            new \DateTime('2016-12-13 00:00'),
            $this->addBusinessDays(new \DateTime('2016-12-09 00:00'), 2)
        );
    }

    public function testSubBusinessDays()
    {
        // mon
        $this->assertEquals(
            new \DateTime('2016-12-05 00:00'),
            $this->subBusinessDays(new \DateTime('2016-12-07 00:00'), 2)
        );

        // fri
        $this->assertEquals(
            new \DateTime('2016-12-09 00:00'),
            $this->subBusinessDays(new \DateTime('2016-12-13 00:00'), 2)
        );
    }
}
