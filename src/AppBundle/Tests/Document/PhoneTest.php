<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Phone;

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
        $this->assertEquals(77.88, $phoneA->getYearlyPolicyPrice());
        $this->assertEquals(83.88, $phoneB->getYearlyPolicyPrice());
    }

    public function testYearlyLoss()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        $this->assertEquals(18, $phoneA->getYearlyLossPrice());
        $this->assertEquals(18, $phoneB->getYearlyLossPrice());
    }

    public function testMaxPot()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        // 76.704
        $this->assertEquals(76.70, $phoneA->getMaxPot());
        // 81.504
        $this->assertEquals(81.50, $phoneB->getMaxPot());
    }

    public function testMaxConnections()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        // 76.704
        $this->assertEquals(8, $phoneA->getMaxConnections());
        // 81.504
        $this->assertEquals(9, $phoneB->getMaxConnections());
    }

    public function testPolicy2dp()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.990001, 1.5);
        $this->assertEquals(6.99, $phone->getPolicyPrice());
    }

    public function testLoss2dp()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.99, 1.50001);
        $this->assertEquals(1.50, $phone->getLossPrice());
    }
    
    private function getSamplePhoneA()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 5s', 6.49, 1.5);

        return $phone;
    }

    private function getSamplePhoneB()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.99, 1.5);

        return $phone;
    }
}
