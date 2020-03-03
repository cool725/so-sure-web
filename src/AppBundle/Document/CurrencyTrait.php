<?php

namespace AppBundle\Document;

trait CurrencyTrait
{
    public function getCurrentVatRate(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        return 0.2;
    }

    public function getCurrentIptRate(\DateTime $date = null)
    {
        return self::staticGetCurrentIptRate($date);
    }

    public function staticGetCurrentIptRate(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if ($date < new \DateTime('2016-10-01')) {
            return 0.095;
        } elseif ($date < new \DateTime('2017-06-01')) {
            return 0.1;
        } else {
            return 0.12;
        }
    }

    protected function withIpt($basePrice, \DateTime $date = null)
    {
        return $this->toTwoDp($basePrice * (1+$this->getCurrentIptRate($date)));
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

    public function convertToPennies($float)
    {
        $pennies = $this->toTwoDp($float) * 100;

        return $this->toZeroDp($pennies);
    }

    public function convertFromPennies($float)
    {
        return $this->toTwoDp($float / 100);
    }

    public function toZeroDp($float)
    {
        return number_format(round($float, 0), 0, ".", "");
    }

    public static function toTwoDp($float)
    {
        return number_format(round($float, 2), 2, ".", "");
    }

    public function toFourDp($float)
    {
        return number_format(round($float, 4), 4, ".", "");
    }

    public static function staticToTwoDp($float)
    {
        return number_format(round($float, 2), 2, ".", "");
    }

    public function areEqualToTwoDp($float1, $float2)
    {
        return abs($float1 - $float2) < 0.001;
    }

    public static function staticAreEqualToTwoDp($float1, $float2)
    {
        return abs($float1 - $float2) < 0.001;
    }

    public function areEqualToFourDp($float1, $float2)
    {
        if (null === $float1 || null === $float2) {
            return false;
        }

        return abs($float1 - $float2) < 0.00001;
    }

    public function areEqualToSixDp($float1, $float2)
    {
        if (null === $float1 || null === $float2) {
            return false;
        }

        return abs($float1 - $float2) < 0.0000001;
    }

    public function greaterThanZero($float)
    {
        return $float > 0 && !$this->areEqualToTwoDp(0, $float);
    }

    public function isWholeInteger($value)
    {
        return $this->areEqualToFourDp(0, $value - floor($value)) ||
                    $this->areEqualToFourDp(0, ceil($value) - $value);
    }
}
