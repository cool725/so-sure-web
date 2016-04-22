<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePremium;

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
        $this->assertEquals(77.88, $phoneA->getCurrentPolicyPremium()->getYearlyPolicyPrice());
        $this->assertEquals(83.88, $phoneB->getCurrentPolicyPremium()->getYearlyPolicyPrice());
    }

    public function testYearlyLoss()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        $this->assertEquals(18, $phoneA->getCurrentPolicyPremium()->getYearlyLossPrice());
        $this->assertEquals(18, $phoneB->getCurrentPolicyPremium()->getYearlyLossPrice());
    }

    public function testMaxPot()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        // 76.704
        $this->assertEquals(76.70, $phoneA->getCurrentPolicyPremium()->getMaxPot());
        // 81.504
        $this->assertEquals(81.50, $phoneB->getCurrentPolicyPremium()->getMaxPot());
    }

    public function testMaxConnections()
    {
        $phoneA = $this->getSamplePhoneA();
        $phoneB = $this->getSamplePhoneB();
        // 76.704
        $this->assertEquals(8, $phoneA->getCurrentPolicyPremium()->getMaxConnections());
        // 81.504
        $this->assertEquals(9, $phoneB->getCurrentPolicyPremium()->getMaxConnections());
    }

    public function testPolicy2dp()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.990001, 1.5);
        $this->assertEquals(6.99, $phone->getCurrentPolicyPremium()->getPolicyPrice());
    }

    public function testLoss2dp()
    {
        $phone = new Phone();
        $phone->init('Apple', 'iPhone 6', 6.99, 1.50001);
        $this->assertEquals(1.50, $phone->getCurrentPolicyPremium()->getLossPrice());
    }
    
    public function testGetCurrentPolicyPremium()
    {
        $phone = new Phone();
        $premium = new PhonePremium();
        $premium->setValidFrom(new \DateTime('2016-01-01'));
        $phone->addPolicyPremium($premium);

        $this->assertEquals(new \DateTime('2016-01-01'), $phone->getCurrentPolicyPremium()->getValidFrom());
    }

    public function testMultipleGetCurrentPolicyPremium()
    {
        $phone = new Phone();
        $premiumA = new PhonePremium();
        $premiumA->setValidFrom(new \DateTime('2016-01-01'));
        $premiumA->setValidTo(new \DateTime('2016-01-02 23:59:59'));
        $phone->addPolicyPremium($premiumA);
        $premiumB = new PhonePremium();
        $premiumB->setValidFrom(new \DateTime('2016-01-03'));
        $phone->addPolicyPremium($premiumB);

        $this->assertEquals(new \DateTime('2016-01-03'), $phone->getCurrentPolicyPremium()->getValidFrom());
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
