<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePrice;

/**
 * @group unit
 */
class PhonePriceTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testCreatePremium()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2016-05-01');
        $premium = $phonePrice->createPremium($date);
        $this->assertEquals(0.48, $premium->getIpt());
        $this->assertEquals(5, $premium->getGwp());
        $this->assertEquals(0.095, $premium->getIptRate());
    }

    public function testCreatePremiumNewIptRate()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2016-10-01');
        $premium = $phonePrice->createPremium($date);
        $this->assertEquals(0.50, $premium->getIpt());
        $this->assertEquals(5, $premium->getGwp());
        $this->assertEquals(0.1, $premium->getIptRate());
    }

    public function testGetAdjustedPremiumPrices()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2016-10-01');
        $premium = $phonePrice->createPremium($date);

        $this->assertEquals(5.5, $phonePrice->getAdjustedInitialMonthlyPremiumPrice(0, $date));
        $this->assertEquals(5.5, $phonePrice->getAdjustedStandardMonthlyPremiumPrice(0, $date));
        $this->assertEquals(66, $phonePrice->getAdjustedYearlyPremiumPrice(0, $date));

        $this->assertEquals(4.5, $phonePrice->getAdjustedInitialMonthlyPremiumPrice(12, $date));
        $this->assertEquals(4.5, $phonePrice->getAdjustedInitialMonthlyPremiumPrice(12, $date));
        $this->assertEquals(54, $phonePrice->getAdjustedYearlyPremiumPrice(12, $date));

        $this->assertEquals(4.63, $phonePrice->getAdjustedInitialMonthlyPremiumPrice(10, $date));
        $this->assertEquals(4.67, $phonePrice->getAdjustedStandardMonthlyPremiumPrice(10, $date));
        $this->assertEquals(56, $phonePrice->getAdjustedYearlyPremiumPrice(10, $date));
    }
}
