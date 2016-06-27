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
}
