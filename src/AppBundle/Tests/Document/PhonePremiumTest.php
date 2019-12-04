<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePremium;

/**
 * @group unit
 */
class PhonePremiumTest extends \PHPUnit\Framework\TestCase
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

        $this->assertTrue($phonePremium->isEvenlyDivisible(5.5));

        $this->assertEquals(12, $phonePremium->getNumberOfScheduledMonthlyPayments(5.5));
    }

    public function testYearlyPremium()
    {
        $phonePremium = new PhonePremium();
        $phonePremium->setGwp(5);
        $phonePremium->setIpt(0.5);
        $this->assertEquals(5.5 * 12, $phonePremium->getYearlyPremiumPrice());

        $this->assertEquals(66, $phonePremium->getAdjustedYearlyPremiumPrice());

        $this->assertTrue($phonePremium->isEvenlyDivisible(66));

        $this->assertEquals(12, $phonePremium->getNumberOfMonthlyPayments(66));

        $this->assertEquals(1, $phonePremium->getNumberOfScheduledMonthlyPayments(66));
    }

    public function testMonthlyPremiumWithDiscount()
    {
        $phonePremium = new PhonePremium();
        $phonePremium->setGwp(5);
        $phonePremium->setIpt(0.5);
        $phonePremium->setAnnualDiscount(10);
        $this->assertEquals(4.67, $phonePremium->getAdjustedStandardMonthlyPremiumPrice());
        $this->assertEquals(4.63, $phonePremium->getAdjustedFinalMonthlyPremiumPrice());

        $this->assertTrue($phonePremium->isEvenlyDivisible(4.67, true));

        $this->assertEquals(12, $phonePremium->getNumberOfScheduledMonthlyPayments(4.67));
    }

    public function testMonthlyPremiumWithDiscountPreRenewal()
    {
        $phonePremium = new PhonePremium();
        $phonePremium->setGwp(5);
        $phonePremium->setIpt(0.5);
        $this->assertEquals(4.67, $phonePremium->getAdjustedStandardMonthlyPremiumPrice(10));
        $this->assertEquals(4.63, $phonePremium->getAdjustedFinalMonthlyPremiumPrice(10));
        $this->assertEquals(5.5, $phonePremium->getAdjustedStandardMonthlyPremiumPrice());
        $this->assertEquals(5.5, $phonePremium->getAdjustedFinalMonthlyPremiumPrice());
    }

    public function testYearlyPremiumWithDiscount()
    {
        $phonePremium = new PhonePremium();
        $phonePremium->setGwp(5);
        $phonePremium->setIpt(0.5);
        $phonePremium->setAnnualDiscount(10);
        $this->assertEquals(56, $phonePremium->getAdjustedYearlyPremiumPrice());

        $this->assertTrue($phonePremium->isEvenlyDivisible(56, true));

        $this->assertEquals(12, $phonePremium->getNumberOfMonthlyPayments(56));

        $this->assertEquals(1, $phonePremium->getNumberOfScheduledMonthlyPayments(56));
    }

    public function testYearlyPremiumWithDiscountPreRenewal()
    {
        $phonePremium = new PhonePremium();
        $phonePremium->setGwp(5);
        $phonePremium->setIpt(0.5);
        $this->assertEquals(56, $phonePremium->getAdjustedYearlyPremiumPrice(10));
        $this->assertEquals(66, $phonePremium->getAdjustedYearlyPremiumPrice());
    }

    /**
     * Tests to see if the yearly gwp calculation yields the same value as does the stored gwp.
     */
    public function testPointlessYearlyGwpCalculation()
    {
        $premium = new PhonePremium();
        $premium->setGwp(10.2);
        $premium->setIpt(0.9);

    }
}
