<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePremium;

/**
 * @group unit
 */
class PhonePremiumTest extends \PHPUnit\Framework\TestCase
{
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
     * Shows that the value actual gwp calculates is basically the same thing as the gwp x 12, it's just that they
     * differ due to rounding errors to the tune of a few pennies, a phenomenon also seen in
     * OneOffCommand::oneFreeMonth.
     */
    public function testYearlyGwpCalculationPurpose()
    {
        $gwp = 10.123768;
        $ipt = 1.3469872;
        $premium = new PhonePremium();
        $premium->setGwp($gwp);
        $premium->setIpt($ipt);
        $premium->setIptRate($ipt / $gwp);
        $this->assertEquals($premium->getYearlyGwpActual(), $premium->getGwp() * 12, "Should be close", 0.05);
        $this->assertNotEquals($premium->getYearlyGwpActual(), $premium->getGwp() * 12, "But not too close", 0.01);
    }
}
