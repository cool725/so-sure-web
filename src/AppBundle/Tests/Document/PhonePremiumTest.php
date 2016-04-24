<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePremium;

/**
 * @group unit
 */
class PhonePremiumTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testMonthlyPremium()
    {
        $phonePremium = new PhonePremium();
        $phonePremium->setGwp(5);
        $phonePremium->setIpt(0.5);
        $this->assertEquals(5.5, $phonePremium->getMonthlyPremiumPrice());
    }

    public function testYearlyPremium()
    {
        $phonePremium = new PhonePremium();
        $phonePremium->setGwp(5);
        $phonePremium->setIpt(0.5);
        $this->assertEquals(5.5 * 12, $phonePremium->getYearlyPremiumPrice());
    }
}
