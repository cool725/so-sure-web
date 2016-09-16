<?php

namespace AppBundle\Document;

trait CurrencyTrait
{
    public function getCurrentIptRate(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        if ($date < new \DateTime('2016-10-01')) {
            return 0.095;
        } else {
            return 0.1;
        }
    }

    protected function withIpt($basePrice)
    {
        return $this->toTopTwoDp($basePrice * (1+$this->getCurrentIptRate()));
    }

    protected function toTopTwoDp($float)
    {
        // TODO: Is this necessary?
        // float may have a .00000001 or .9999999 value, so make sure we round
        return $this->ceilToDp(round($float, 6), 2);
    }

    protected function ceilToDp($float, $dp)
    {
        $multiplier = pow(10, $dp);
        return ceil($float * $multiplier) / $multiplier;
    }

    public function toTwoDp($float)
    {
        return number_format(round($float, 2), 2, ".", ",");
    }

    public function toFourDp($float)
    {
        return number_format(round($float, 4), 4, ".", ",");
    }

    public static function staticToTwoDp($float)
    {
        return number_format(round($float, 2), 2, ".", ",");
    }
}
