<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\CurrencyTrait;

/**
 * @group unit
 */
class CurrencyTraitTest extends \PHPUnit\Framework\TestCase
{
    use CurrencyTrait;

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testAreEqualToTwoDp()
    {
        $this->assertTrue($this->areEqualToTwoDp(15.023, 15.024));
        $this->assertFalse($this->areEqualToTwoDp(15.02, 15.03));
        $this->assertFalse($this->areEqualToTwoDp(null, 1));
    }

    public function testAreEqualToFourDp()
    {
        $this->assertTrue($this->areEqualToFourDp(15.02532, 15.02533));
        $this->assertFalse($this->areEqualToFourDp(15.0253, 15.0254));
        $this->assertFalse($this->areEqualToFourDp(null, 1));
    }

    public function testCurrentIptRate()
    {
        $this->assertEquals('0.095', $this->getCurrentIptRate(new \DateTime('2016-09-30 23:59')));
        $this->assertEquals('0.1', $this->getCurrentIptRate(new \DateTime('2016-10-01')));
        $this->assertEquals('0.1', $this->getCurrentIptRate(new \DateTime('2017-05-31 23:59')));
        $this->assertEquals('0.12', $this->getCurrentIptRate(new \DateTime('2017-06-01')));
    }

    public function testWithIpt()
    {
        $this->assertEquals('13.99', $this->withIpt(12.49, new \DateTime('2018-06-01')));
        $this->assertEquals('14.49', $this->withIpt(12.94, new \DateTime('2018-06-01')));
    }

    public function testStaticToTwoDp()
    {
        $this->assertEquals('1298.02', $this->staticToTwoDp(1298.02));
    }

    public function testConvertToPennies()
    {
        $this->assertEquals(6732, $this->convertToPennies(67.32));
    }

    public function testConvertFromPennies()
    {
        $this->assertEquals(67.32, $this->convertFromPennies(6732));
    }
}
