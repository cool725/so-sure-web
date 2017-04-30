<?php

namespace AppBundle\Document;

trait DateTrait
{
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
            sprintf('%d-%d-%d 00:00:00', $date->format('Y'), $date->format('m'), $date->format('d'))
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

    public function addBusinessDays($date, $days)
    {
        $businessDays = clone $date;
        while ($days > 0) {
            $businessDays->add(new \DateInterval('P1D'));
            if (!in_array((int) $businessDays->format('w'), [0, 6])) {
                $days--;
            }
        }

        return $businessDays;
    }

    public function subBusinessDays($date, $days)
    {
        $businessDays = clone $date;
        while ($days > 0) {
            $businessDays->sub(new \DateInterval('P1D'));
            if (!in_array((int) $businessDays->format('w'), [0, 6])) {
                $days--;
            }
        }

        return $businessDays;
    }

    public function dateDiffMonths($date1, $date2, $ceil = true)
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

    public function clearTime($date)
    {
        return $date->setTime(0, 0);
    }
}
