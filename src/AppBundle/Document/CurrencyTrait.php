<?php

namespace AppBundle\Document;

trait CurrencyTrait
{
    public function toTwoDp($float)
    {
        return round($float, 2);
    }
}
