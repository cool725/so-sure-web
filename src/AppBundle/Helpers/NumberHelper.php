<?php

namespace AppBundle\Helpers;

/**
 * Helps with mathematical operations and things like that.
 */
class NumberHelper
{
    /**
     * Tells you if two numbers are close to each other within a given tolerance.
     * @param float $a         is the first number
     * @param float $b         is the second number.
     * @param float $tolerance is the maximum amount of difference that there can be.
     * @return boolean true if they are within the tolerance of each other, and false if not.
     */
    public static function equalTo($a, $b, $tolerance)
    {
        return abs($a - $b) <= $tolerance;
    }
}
