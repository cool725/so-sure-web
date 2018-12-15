<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\PhonePrice;
use AppBundle\Document\PolicyTerms;

/**
 * @group unit
 */
class PhonePriceTest extends \PHPUnit\Framework\TestCase
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
        $premium = $phonePrice->createPremium(null, $date);
        $this->assertEquals(0.48, $premium->getIpt());
        $this->assertEquals(5, $premium->getGwp());
        $this->assertEquals(0.095, $premium->getIptRate());
    }

    public function testCreatePremiumNewIptRate()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2016-10-01');
        $premium = $phonePrice->createPremium(null, $date);
        $this->assertEquals(0.50, $premium->getIpt());
        $this->assertEquals(5, $premium->getGwp());
        $this->assertEquals(0.1, $premium->getIptRate());
    }

    public function testCreatePremiumNewNewIptRate()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2017-06-01');
        $premium = $phonePrice->createPremium(null, $date);
        $this->assertEquals(0.60, $premium->getIpt());
        $this->assertEquals(5, $premium->getGwp());
        $this->assertEquals(0.12, $premium->getIptRate());
    }

    public function testCreatePremiumAdditionalPremium()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2017-06-01');
        $premium = $phonePrice->createPremium(41.67, $date);
        $this->assertEquals(46.67, $premium->getGwp());
        $this->assertEquals(5.60, $premium->getIpt());
        $this->assertEquals(0.12, $premium->getIptRate());
    }

    public function testCreatePremiumExcess()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $phonePrice->setExcess(PolicyTerms::getHighExcess());
        $phonePrice->setPicSureExcess(PolicyTerms::getLowExcess());
        $date = new \DateTime('2017-06-01');
        $premium = $phonePrice->createPremium(41.67, $date);
        $this->assertTrue($premium->getPicSureExcess()->equal(PolicyTerms::getLowExcess()));
        $this->assertTrue($premium->getExcess()->equal(PolicyTerms::getHighExcess()));
    }

    public function testGetAdjustedPremiumPrices()
    {
        $phonePrice = new PhonePrice();
        $phonePrice->setGwp(5);
        $date = new \DateTime('2016-10-01');
        $premium = $phonePrice->createPremium(null, $date);

        $this->assertEquals(5.5, $phonePrice->getAdjustedFinalMonthlyPremiumPrice(0, $date));
        $this->assertEquals(5.5, $phonePrice->getAdjustedStandardMonthlyPremiumPrice(0, $date));
        $this->assertEquals(66, $phonePrice->getAdjustedYearlyPremiumPrice(0, $date));

        $this->assertEquals(4.5, $phonePrice->getAdjustedFinalMonthlyPremiumPrice(12, $date));
        $this->assertEquals(4.5, $phonePrice->getAdjustedStandardMonthlyPremiumPrice(12, $date));
        $this->assertEquals(54, $phonePrice->getAdjustedYearlyPremiumPrice(12, $date));

        $this->assertEquals(4.63, $phonePrice->getAdjustedFinalMonthlyPremiumPrice(10, $date));
        $this->assertEquals(4.67, $phonePrice->getAdjustedStandardMonthlyPremiumPrice(10, $date));
        $this->assertEquals(56, $phonePrice->getAdjustedYearlyPremiumPrice(10, $date));
    }
}
