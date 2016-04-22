<?php

namespace AppBundle\Document;

trait CurrencyTrait
{
    public function getIptRate()
    {
        // TODO - check date
        return 0.095;
    }

    protected function withIpt($basePrice)
    {
        return $this->toTopTwoDp($basePrice * (1+$this->getIptRate()));
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
        return round($float, 2);
    }
}
