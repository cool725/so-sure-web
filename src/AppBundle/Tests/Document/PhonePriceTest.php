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
}
