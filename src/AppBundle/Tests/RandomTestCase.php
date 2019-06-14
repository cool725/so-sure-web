<?php

namespace AppBundle\Tests;

/**
 * Provides capability for tests on random data that always includes the minimum and maximum.
 */
class RandomTestCase extends \PHPUnit\Framework\TestCase
{
    const RANDOM_ITERATIONS = 8;

    /**
     * Gives a list of generators that can be used to run a test driven by random numbers.
     * @return array of callback functions all taking a min and a max and returning a number.
     */
    public static function randomFunctions()
    {
        $rand = function ($a, $b) {
            return rand($a, $b);
        };
        $min = function ($a, $b) {
            return $a;
        };
        $max = function ($a, $b) {
            return $b;
        };
        $functions = [[$min], [$max]];
        for ($i = 0; $i < self::RANDOM_ITERATIONS; $i++) {
            array_push($functions, [$rand]);
        }
        return $functions;
    }
}
