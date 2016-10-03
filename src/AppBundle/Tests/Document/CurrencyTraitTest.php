<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\CurrencyTrait;

/**
 * @group unit
 */
class CurrencyTraitTest extends \PHPUnit_Framework_TestCase
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
}
