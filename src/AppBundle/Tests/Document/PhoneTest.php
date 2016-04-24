<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;

/**
 * @group unit
 */
class PhoneTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testYearlyPremium()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        $this->assertEquals(95.88, $phoneA->getCurrentPhonePrice()->getYearlyPremiumPrice());
        $this->assertEquals(101.88, $phoneB->getCurrentPhonePrice()->getYearlyPremiumPrice());
    }

    public function testMaxPot()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        // 76.704
        $this->assertEquals(76.70, $phoneA->getCurrentPhonePrice()->getMaxPot());
        // 81.504
        $this->assertEquals(81.50, $phoneB->getCurrentPhonePrice()->getMaxPot());
    }

    public function testMaxConnections()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        // 76.704
        $this->assertEquals(8, $phoneA->getCurrentPhonePrice()->getMaxConnections());
        // 81.504
        $this->assertEquals(9, $phoneB->getCurrentPhonePrice()->getMaxConnections());
    }

    public function testPolicy2dp()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.990001, 1.5);
        $this->assertEquals(6.99, $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
    }
    
    public function testGetCurrentPhonePrice()
    {
        $phone = new Phone();
        $phonePrice = new PhonePrice();
        $phonePrice->setValidFrom(new \DateTime('2016-01-01'));
        $phone->addPhonePrice($phonePrice);

        $this->assertEquals(new \DateTime('2016-01-01'), $phone->getCurrentPhonePrice()->getValidFrom());
    }

    public function testMultipleGetCurrentPhonePrice()
    {
        $phone = new Phone();
        $phonePriceA = new PhonePrice();
        $phonePriceA->setValidFrom(new \DateTime('2016-01-01'));
        $phonePriceA->setValidTo(new \DateTime('2016-01-02 23:59:59'));
        $phone->addPhonePrice($phonePriceA);
        $phonePriceB = new PhonePrice();
        $phonePriceB->setValidFrom(new \DateTime('2016-01-03'));
        $phone->addPhonePrice($phonePriceB);

        $this->assertEquals(new \DateTime('2016-01-03'), $phone->getCurrentPhonePrice()->getValidFrom());
    }

    private function getSamplePhoneA()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 5s', 6.49 + 1.5);

        return $phone;
    }

    private function getSamplePhoneB()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.99 + 1.5);

        return $phone;
    }
}
