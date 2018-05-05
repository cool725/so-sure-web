<?php

namespace AppBundle\Document;

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

    public function isBankHoliday(\DateTime $date)
    {
        foreach (static::getBankHolidays() as $bankHoliday) {
            if ($bankHoliday->diff($date)->days == 0) {
                return true;
            }
        }

        return false;
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
            $date = new \DateTime();
        }
        $startMonth = new \DateTime(sprintf('%d-%d-01 00:00:00', $date->format('Y'), $date->format('m')));

        return $startMonth;
    }

    public function endOfMonth(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }
        $startMonth = $this->startOfMonth($date);
        $nextMonth = clone $startMonth;
        $nextMonth->add(new \DateInterval('P1M'));

        return $nextMonth;
    }

    public function startOfDay(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
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
            $date = new \DateTime();
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

    public function isWeekDay(\DateTime $date)
    {
        return !in_array((int) $date->format('w'), [0, 6]);
    }

    public function getCurrentOrNextBusinessDay(\DateTime $date, \DateTime $now = null)
    {
        if (!$now) {
            $now = new \DateTime();
        }

        // make sure we don't run in the past
        if ($date < $now) {
            $businessDays = $now;
        } else {
            $businessDays = clone $date;
        }

        if ($this->isWeekDay($businessDays) && !$this->isBankHoliday($businessDays)) {
            return $businessDays;
        }

        return $this->addBusinessDays($businessDays, 1);
    }

    public function addBusinessDays($date, $days)
    {
        $businessDays = clone $date;
        while ($days > 0) {
            $isBusinessDay = true;
            $businessDays->add(new \DateInterval('P1D'));
            if (!$this->isWeekDay($businessDays)) {
                $isBusinessDay = false;
            } elseif ($this->isBankHoliday(($businessDays))) {
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
            if (!$this->isWeekDay($businessDays)) {
                $isBusinessDay = false;
            } elseif ($this->isBankHoliday(($businessDays))) {
                $isBusinessDay = false;
            }

            if ($isBusinessDay) {
                $days--;
            }
        }

        return $businessDays;
    }

    public function dateDiffMonths(\DateTime $date1, \DateTime $date2, $ceil = true)
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

        return $months;
    }

    public function adjustDayForBilling($date, $adjustTimeIfAdjusted = false)
    {
        $billingDate = clone $date;
        if ($billingDate->format('d') > 28) {
            $billingDate->sub(new \DateInterval(sprintf('P%dD', $billingDate->format('d') - 28)));
            if ($adjustTimeIfAdjusted) {
                $billingDate->setTime(23, 59, 59);
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
}
