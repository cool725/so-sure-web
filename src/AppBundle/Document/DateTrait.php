<?php

namespace AppBundle\Document;

use AppBundle\Classes\SoSure;

trait DateTrait
{
    public static function getBankHolidays()
    {
        // https://www.gov.uk/bank-holidays
        return [
            new \DateTime('2018-01-01'),
            new \DateTime('2018-03-30'),
            new \DateTime('2018-04-02'),
            new \DateTime('2018-05-07'),
            new \DateTime('2018-05-28'),
            new \DateTime('2018-08-27'),
            new \DateTime('2018-12-25'),
            new \DateTime('2018-12-26'),
            new \DateTime('2019-01-01'),
            new \DateTime('2019-04-19'),
            new \DateTime('2019-04-22'),
            new \DateTime('2019-05-06'),
            new \DateTime('2019-05-27'),
            new \DateTime('2019-08-26'),
            new \DateTime('2019-12-25'),
            new \DateTime('2019-12-26'),
        ];
    }

    public static function isBankHoliday(\DateTime $date)
    {
        foreach (static::getBankHolidays() as $bankHoliday) {
            if ($bankHoliday->diff($date)->days == 0) {
                return true;
            }
        }

        return false;
    }

    public function now()
    {
        return \DateTime::createFromFormat('U', time());
    }

    public function startOfPreviousMonth(\DateTime $date = null)
    {
        $startMonth = $this->startOfMonth($date);
        $previousMonth = clone $startMonth;
        $previousMonth->sub(new \DateInterval('P1M'));

        return $previousMonth;
    }

    public function startOfMonth(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime('now', new \DateTimeZone(SoSure::TIMEZONE));
        }

        // We want to change reporting to use Europe/London rather than UTC
        // in order to keep historic data accurate, only adjust data going forwards from Apr 2018
        $tz = 'UTC';
        if ($date >= new \DateTime('2018-04-01 00:00:00', new \DateTimeZone(SoSure::TIMEZONE))) {
            $tz = SoSure::TIMEZONE;
        }

        $startMonth = new \DateTime(
            sprintf('%d-%d-01 00:00:00', $date->format('Y'), $date->format('m')),
            new \DateTimeZone($tz)
        );

        // due to change from UTC to Europe/London reporting in Apr 2018, we have an overlap for this particular month
        // avoid double counting by incrementing 1 hour
        if ($startMonth == new \DateTime('2018-04-01 00:00:00', new \DateTimeZone(SoSure::TIMEZONE))) {
            $startMonth = new \DateTime('2018-04-01 01:00:00', new \DateTimeZone(SoSure::TIMEZONE));
        }
        /*
        if ($startMonth == new \DateTime('2018-05-01 00:00:00', new \DateTimeZone(SoSure::TIMEZONE))) {
            $startMonth = new \DateTime('2018-05-01 01:00:00', new \DateTimeZone(SoSure::TIMEZONE));
        }
        */

        return $startMonth;
    }

    public function startOfYear(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $startYear = new \DateTime(sprintf('%d-01-01 00:00:00', $date->format('Y')));

        return $startYear;
    }

    public function endOfMonth(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime('now', new \DateTimeZone(SoSure::TIMEZONE));
        }

        // We want to change reporting to use Europe/London rather than UTC
        // in order to keep historic data accurate, only adjust data going forwards from Apr 2018
        $tz = 'UTC';
        if ($date >= new \DateTime('2018-04-01 00:00:00', new \DateTimeZone(SoSure::TIMEZONE))) {
            $tz = SoSure::TIMEZONE;
        }

        if ($date->format('m') == 12) {
            $nextMonth = new \DateTime(
                sprintf('%d-01-01 00:00:00', $date->format('Y') + 1),
                new \DateTimeZone($tz)
            );
        } else {
            $nextMonth = new \DateTime(
                sprintf('%d-%d-01 00:00:00', $date->format('Y'), $date->format('m') + 1),
                new \DateTimeZone($tz)
            );
        }

        return $nextMonth;
    }

    public function startOfDay(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $startMonth = new \DateTime(
            sprintf('%d-%d-%d 00:00:00', $date->format('Y'), $date->format('m'), $date->format('d')),
            $date->getTimezone()
        );

        return $startMonth;
    }

    public function endOfDay(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $startDay = $this->startOfDay($date);
        $nextDay = clone $startDay;
        $nextDay->add(new \DateInterval('P1D'));

        return $nextDay;
    }

    public function addOneSecond($date)
    {
        $after = clone $date;
        $after->add(new \DateInterval('PT1S'));

        return $after;
    }

    public static function isWeekDay(\DateTime $date)
    {
        return !in_array((int) $date->format('w'), [0, 6]);
    }

    public function getCurrentOrNextBusinessDay(\DateTime $date, \DateTime $now = null)
    {
        if (!$now) {
            $now = \DateTime::createFromFormat('U', time());
        }

        // make sure we don't run in the past
        if ($date < $now) {
            $businessDays = $now;
        } else {
            $businessDays = clone $date;
        }

        if (static::isWeekDay($businessDays) && !static::isBankHoliday($businessDays)) {
            return $businessDays;
        }

        return $this->addBusinessDays($businessDays, 1);
    }

    public function getCurrentOrPreviousBusinessDay(\DateTime $date, \DateTime $now = null)
    {
        if (!$now) {
            $now = \DateTime::createFromFormat('U', time());
        }

        // make sure we don't run in the past
        if ($date < $now) {
            $businessDays = $now;
        } else {
            $businessDays = clone $date;
        }

        if (static::isWeekDay($businessDays) && !static::isBankHoliday($businessDays)) {
            return $businessDays;
        }

        return $this->subBusinessDays($businessDays, 1);
    }

    public function getNextBusinessDay(\DateTime $date, \DateTime $now = null)
    {
        if (!$now) {
            $now = \DateTime::createFromFormat('U', time());
        }

        // make sure we don't run in the past
        if ($date < $now) {
            $businessDays = $now;
        } else {
            $businessDays = clone $date;
        }

        return $this->addBusinessDays($businessDays, 1);
    }

    /**
     * @param \DateTime $date
     * @param integer   $days
     * @return \DateTime
     * @throws \Exception
     */
    public static function addBusinessDays(\DateTime $date, $days)
    {
        $businessDays = clone $date;
        while ($days > 0) {
            $isBusinessDay = true;
            $businessDays->add(new \DateInterval('P1D'));
            if (!static::isWeekDay($businessDays)) {
                $isBusinessDay = false;
            } elseif (static::isBankHoliday(($businessDays))) {
                $isBusinessDay = false;
            }

            if ($isBusinessDay) {
                $days--;
            }
        }

        return $businessDays;
    }

    public function subBusinessDays($date, $days)
    {
        $businessDays = clone $date;
        while ($days > 0) {
            $isBusinessDay = true;
            $businessDays->sub(new \DateInterval('P1D'));
            if (!static::isWeekDay($businessDays)) {
                $isBusinessDay = false;
            } elseif (static::isBankHoliday(($businessDays))) {
                $isBusinessDay = false;
            }

            if ($isBusinessDay) {
                $days--;
            }
        }

        return $businessDays;
    }

    public function dateDiffMonths(\DateTime $date1, \DateTime $date2, $ceil = true, $diffIfSame = false)
    {
        if ($date1 < $date2) {
            return 0;
        }
        $diff = $date1->diff($date2);
        $months = $diff->m + $diff->y * 12;
        if ($ceil) {
            if ($diff->d > 0 || $diff->h > 0 || $diff->i > 0 || $diff->s > 0) {
                $months++;
            }
        }
        if ($date1 == $date2 && $diffIfSame) {
            $months = 1;
        }

        return $months;
    }

    public function adjustDayForBilling($date, $adjustTimeIfAdjusted = false)
    {
        $billingDate = clone $date;
        $billingDate = self::convertTimezone($billingDate, new \DateTimeZone(SoSure::TIMEZONE));
        if ($billingDate->format('d') > 28) {
            $billingDate->sub(new \DateInterval(sprintf('P%dD', $billingDate->format('d') - 28)));
            if ($adjustTimeIfAdjusted) {
                $billingDate->setTime(22, 59, 59);
            }
        }

        return $billingDate;
    }

    public function setDayOfMonth($date, $day)
    {
        $adjustedDate = clone $date;
        $adjustedDate->modify(sprintf('-%d day', $adjustedDate->format('j') - $day));

        return $adjustedDate;
    }

    public function clearTime($date)
    {
        return $date->setTime(0, 0);
    }

    public function getClaimResponseTime(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime('now', new \DateTimeZone(SoSure::TIMEZONE));
        }
        $time = 'in the next 3 hours';
        if (!static::isWeekDay($date) || static::isBankHoliday($date)) {
            $time = 'on the morning of the next working day';
        } elseif ($date->format('G') < 9) {
            $time = 'by 11am';
        } elseif ($date->format('G') >= 16) {
            $time = 'on the morning of the next working day';
        }

        return $time;
    }

    public function isSameDay(\DateTime $date1, \DateTime $date2)
    {
        $diff = $date1->diff($date2);
        if ($diff->d == 0 && $diff->h <= 1) {
            return true;
        }

        return false;
    }

    public static function convertTimezone(\DateTime $date, \DateTimeZone $timezone)
    {
        $adjustedDate = clone $date;
        $adjustedDate = \DateTime::createFromFormat('U', $adjustedDate->getTimestamp());
        $adjustedDate->setTimezone($timezone);

        return $adjustedDate;
    }

    /**
     * Adds a period of time to a given date.
     * @param \DateTime $date  is the date to add onto.
     * @param int       $units is the quantity of time units to add to the date.
     * @param String    $type  is the type of time unit to add.
     * @return \DateTime the given date with the extra units added to it.
     */
    public static function addTime($date, $units, $type)
    {
        $interval = new \DateInterval("PT".abs($units).$type);
        $interval->invert = $units < 0 ? 1 : 0;
        $date = clone $date;
        return $date->add($interval);
    }

    /**
     * Returns a copy of a given date which is n days or other unit ahead of it, taking negative numbers into account.
     * @param \DateTime $date is the starting date.
     * @param int       $days is the number of days or other units to move ahead.
     * @return \DateTime the new date.
     */
    public static function addDays($date, $days)
    {
        $date = clone $date;
        $date->add(static::intervalDays($days));
        return $date;
    }

    /**
     * Creates a date interval over a given number of days or other and takes into account negative numbers.
     * @param int $days is the number of days to make the interval cover.
     * @return \DateInterval given number of units as an interval.
     */
    public static function intervalDays($days)
    {
        $interval = new \DateInterval("P".abs($days)."D");
        $interval->invert = $days < 0 ? 1 : 0;
        return $interval;
    }

    /**
     * Converts a date into a formatted string in a given timezone. If the date is null then an empty string is given.
     * @param \DateTime|null $date     is the date to use. it's timezone is irrelevant as it just gets the timestamp.
     * @param \DateTimeZone  $timezone is the timezone to write this date in.
     * @param String         $format   is the format to write the date out with.
     * @return String the date in the requested the format.
     */
    public static function timezoneFormat($date, \DateTimeZone $timezone, $format)
    {
        if (!$date) {
            return "";
        }
        return static::convertTimezone($date, $timezone)->format($format);
    }

    /**
     * Gives the number of days the first date is from the second, with the second defaulting as being now.
     * @param \DateTime $a is the first date.
     * @param \DateTime $b is the second date which defaults to being now.
     * @return int the number of days by which the first date differs from the second, including negative numbers.
     */
    public static function daysFrom(\DateTime $a, \DateTime $b = null)
    {
        if (!$b) {
            $b = new \DateTime();
        }
        $difference = $b->diff($a);
        $days = $difference->days;
        if ($difference->invert) {
            $days *= -1;
        }
        return $days;
    }
}
