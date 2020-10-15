<?php

namespace AppBundle\Tests\Document\Form;

use AppBundle\Document\Form\Bacs;

/**
 * @group unit
 */
class BacsTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Makes sure that get eligible billing days does not include days that are too soon.
     */
    public function testGetEligibleBillingDays()
    {
        $this->assertTrue(static::checkNumbers(
            Bacs::getEligibleBillingDays(new \DateTime('2020-07-01')),
            1,
            28
        ));
        $this->assertTrue(static::checkNumbers(
            Bacs::getEligibleBillingDays(new \DateTime('2020-07-03')),
            1,
            28
        ));
        $this->assertTrue(static::checkNumbers(
            Bacs::getEligibleBillingDays(new \DateTime('2020-07-10')),
            1,
            28
        ));
        $this->assertTrue(static::checkNumbers(
            Bacs::getEligibleBillingDays(new \DateTime('2020-07-15')),
            1,
            28
        ));
        $this->assertTrue(static::checkNumbers(
            Bacs::getEligibleBillingDays(new \DateTime('2020-07-20')),
            1,
            28
        ));
        $this->assertTrue(static::checkNumbers(
            Bacs::getEligibleBillingDays(new \DateTime('2020-07-22')),
            2,
            28
        ));
        $this->assertTrue(static::checkNumbers(
            Bacs::getEligibleBillingDays(new \DateTime('2020-07-27')),
            5,
            28
        ));
        $this->assertTrue(static::checkNumbers(
            Bacs::getEligibleBillingDays(new \DateTime('2020-07-30')),
            10,
            28
        ));
        $this->assertTrue(static::checkNumbers(
            Bacs::getEligibleBillingDays(new \DateTime('2020-08-01')),
            1,
            28
        ));
    }

    /**
     * Checks that the given array is a sequence of consecutive integers containing a given range of numbers.
     * @param array $numbers is the array to check.
     * @param int   $min     is the first number.
     * @param int   $max     is the last number.
     * @return boolean true if the array is all good and false if not.
     * @throws \RuntimeException if you give a max lower than the min which would make no sense.
     */
    private static function checkNumbers($numbers, $min, $max)
    {
        if ($max < $min) {
            throw new \RuntimeException('Max can\'t be less than min');
        }
        $delta = $max - $min;
        if (count($numbers) != $delta + 1) {
            return false;
        }
        for ($i = 0; $i < $delta; $i++) {
            if ($numbers[$i] != $min + $i) {
                return false;
            }
        }
        return true;
    }
}
