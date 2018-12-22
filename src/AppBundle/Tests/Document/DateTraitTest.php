<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\SoSure;
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

    public function testStartOfMonthTimezone()
    {
        // what we expect
        // UTC times
        // 1/3 00:00 - 31/3 23:59
        // 1/4 00:00 - 30/4 22:59
        // 30/4 23:00 - 31/5 22:59
        // 31/5 23:00 - 30/6 22:59

        // Europe/London times
        // 1/3 00:00 - 1/4 00:59 (timechange)
        // 1/4 01:00 - 1/5 00:59
        // 1/5 00:00 - 31/5 23:59
        // 1/6 00:00 - 30/6 23:59

        $this->assertEquals(
            new \DateTime('2018-03-01 00:00'),
            $this->startOfMonth(new \DateTime('2018-03-12 15:00'))
        );

        $this->assertEquals(
            new \DateTime('2018-04-01 01:00', new \DateTimeZone(SoSure::TIMEZONE)),
            $this->startOfMonth(new \DateTime('2018-04-12 15:00'))
        );

        $this->assertEquals(
            new \DateTime('2018-05-01 00:00', new \DateTimeZone(SoSure::TIMEZONE)),
            $this->startOfMonth(new \DateTime('2018-05-12 15:00'))
        );

        $this->assertEquals(
            new \DateTime('2018-06-01 00:00', new \DateTimeZone(SoSure::TIMEZONE)),
            $this->startOfMonth(new \DateTime('2018-06-12 15:00'))
        );
    }

    public function testEndOfMonth()
    {
        $this->assertEquals(
            new \DateTime('2016-03-01 00:00'),
            $this->endOfMonth(new \DateTime('2016-02-12 15:00'))
        );
    }

    public function testEndOfMonthDec()
    {
        $this->assertEquals(
            new \DateTime('2017-01-01 00:00'),
            $this->endOfMonth(new \DateTime('2016-12-12 15:00'))
        );
    }


    public function testEndOfMonthTimezone()
    {
        // what we expect
        // UTC times
        // 1/3 00:00 - 31/3 23:59
        // 1/4 00:00 - 30/4 22:59
        // 30/4 23:00 - 31/5 22:59
        // 31/5 23:00 - 30/6 22:59

        // Europe/London times
        // 1/3 00:00 - 1/4 00:59 (timechange)
        // 1/4 01:00 - 30/4 23:59
        // 1/5 00:00 - 31/5 23:59
        // 1/6 00:00 - 30/6 23:59

        $this->assertEquals(
            new \DateTime('2018-03-01 00:00'),
            $this->endOfMonth(new \DateTime('2018-02-12 15:00'))
        );

        $this->assertEquals(
            new \DateTime('2018-04-01 00:00'),
            $this->endOfMonth(new \DateTime('2018-03-12 15:00'))
        );

        $this->assertEquals(
            new \DateTime('2018-05-01 00:00', new \DateTimeZone(SoSure::TIMEZONE)),
            $this->endOfMonth(new \DateTime('2018-04-12 15:00'))
        );

        $this->assertEquals(
            new \DateTime('2018-06-01 00:00', new \DateTimeZone(SoSure::TIMEZONE)),
            $this->endOfMonth(new \DateTime('2018-05-12 15:00'))
        );

        $this->assertEquals(
            new \DateTime('2018-07-01 00:00', new \DateTimeZone(SoSure::TIMEZONE)),
            $this->endOfMonth(new \DateTime('2018-06-12 15:00'))
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

        $this->assertEquals(
            new \DateTime('2018-03-23 12:04'),
            $this->addBusinessDays(new \DateTime('2018-03-19 12:04'), 4)
        );
    }

    public function testAddBusinessDaysWithHoliday()
    {
        // weekend
        $this->assertEquals(
            new \DateTime('2018-01-02 00:00'),
            $this->addBusinessDays(new \DateTime('2017-12-29 00:00'), 1)
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

        $this->assertEquals(
            new \DateTime('2018-10-22 00:00'),
            $this->subBusinessDays(new \DateTime('2018-10-31 00:00'), 7)
        );
    }

    public function testSubBusinessDaysWithHoliday()
    {
        // weekend
        $this->assertEquals(
            new \DateTime('2017-12-29 00:00'),
            $this->subBusinessDays(new \DateTime('2018-01-02 00:00'), 1)
        );
    }

    public function testCurrentOrNextBusinessDay()
    {
        $now = new \DateTime('2018-03-28 00:00');
        $this->assertEquals(
            new \DateTime('2018-03-29 00:00'),
            $this->getCurrentOrNextBusinessDay(new \DateTime('2018-03-29 00:00'), $now)
        );

        $this->assertEquals(
            new \DateTime('2018-04-03 00:00'),
            $this->getCurrentOrNextBusinessDay(new \DateTime('2018-03-30 00:00'), $now)
        );

        $this->assertEquals(
            new \DateTime('2018-04-16 00:00'),
            $this->getCurrentOrNextBusinessDay(new \DateTime('2018-04-14 00:00'), $now)
        );

        $this->assertEquals(
            new \DateTime('2018-03-28 00:00'),
            $this->getCurrentOrNextBusinessDay(new \DateTime('2018-03-22 00:00'), $now)
        );

    }

    public function testNextBusinessDay()
    {
        $now = new \DateTime('2018-03-28 00:00');
        $this->assertEquals(
            new \DateTime('2018-04-03 00:00'),
            $this->getNextBusinessDay(new \DateTime('2018-03-29 00:00'), $now)
        );

        $this->assertEquals(
            new \DateTime('2018-04-03 00:00'),
            $this->getNextBusinessDay(new \DateTime('2018-03-30 00:00'), $now)
        );

        $this->assertEquals(
            new \DateTime('2018-04-16 00:00'),
            $this->getNextBusinessDay(new \DateTime('2018-04-14 00:00'), $now)
        );

        $this->assertEquals(
            new \DateTime('2018-03-29 00:00'),
            $this->getNextBusinessDay(new \DateTime('2018-03-22 00:00'), $now)
        );
    }

    /**
     * Tests to make sure timezoneFormat works with and without proper dates given.
     */
    public function testTimezoneFormat()
    {
        $utc = new \DateTimeZone("UTC");
        $london = new \DateTimeZone("/Europe/London");
        $this->assertEquals("", static::timezoneFormat(null, $utc, "d/m/Y H:i"));
        $this->assertEquals(
            "01:23 2018-22-03",
            static::timezoneFormat(new \DateTime('2018-03-22 01:23'), $utc, "H:i Y-d-m")
        );
        $this->assertEquals(
            "20/04/2018 01:23",
            static::timezoneFormat(new \DateTime('2018-04-20 00:23'), $london, "d/m/Y H:i")
        );
        $this->assertEquals(
            "01/01/1970 01:00",
            static::timezoneFormat(new \DateTime('1970-01-01 00:00'), $london, "d/m/Y H:i")
        );
    }

    /**
     * Tests to make sure that addTime works as expected.
     */
    public function testAddTime()
    {
        $now = new \DateTime('2018-03-28 00:00');
        $now = static::addTime($now, 3, "H");
        $this->assertEquals(new \DateTime('2018-03-28 03:00'), $now);
        $now = static::addTime($now, -12, "M");
        $this->assertEquals(new \DateTime('2018-03-28 02:48'), $now);
        $now = static::addTime($now, 48, "H");
        $this->assertEquals(new \DateTime('2018-03-30 02:48'), $now);
    }

    /**
     * Tests to make sure addDays works as expected.
     */
    public function testaddDays()
    {
        $now = new \DateTime('2018-03-28 00:00');
        $now = static::addDays($now, 1);
        $this->assertEquals(new \DateTime('2018-03-29 00:00'), $now);
        $now = static::addDays($now, 32);
        $this->assertEquals(new \DateTime('2018-04-30 00:00'), $now);
        $now = static::addDays($now, -40);
        $this->assertEquals(new \DateTime('2018-03-21 00:00'), $now);
        $now = static::addDays($now, -2);
        $this->assertEquals(new \DateTime('2018-03-19 00:00'), $now);
        $now = static::addDays($now, 3);
        $this->assertEquals(new \DateTime('2018-03-22 00:00'), $now);
    }

    /**
     * Makes sure daysFrom gives correct values for negative and positive intervals.
     */
    public function testDaysFrom()
    {
        $now = new \DateTime();
        $other = static::addDays($now, -30);
        $this->assertEquals(-30, static::daysFrom($other, $now));
        $other = static::addDays($other, 5);
        $this->assertEquals(-25, static::daysFrom($other, $now));
        $other = static::addDays($other, 5);
        $this->assertEquals(-20, static::daysFrom($other, $now));
        $other = static::addDays($other, 20);
        $this->assertEquals(0, static::daysFrom($other, $now));
        $other = static::addDays($other, 10);
        $this->assertEquals(10, static::daysFrom($other, $now));
        $other = static::addDays($other, 50);
        $this->assertEquals(60, static::daysFrom($other, $now));
        $other = static::addDays($other, 100);
        $now = static::addDays($now, 99);
        $this->assertEquals(61, static::daysFrom($other, $now));
        $now = static::addDays($now, 60);
        $this->assertEquals(1, static::daysFrom($other, $now));
        $now = static::addDays($now, 1);
        $this->assertEquals(0, static::daysFrom($other, $now));
        $now = static::addDays($now, 1);
        $this->assertEquals(-1, static::daysFrom($other, $now));
        $now = static::addDays($now, 9);
        $this->assertEquals(-10, static::daysFrom($other, $now));
        $now = static::addDays($now, 10);
        $this->assertEquals(-20, static::daysFrom($other, $now));
    }
}
