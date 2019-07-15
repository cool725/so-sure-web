<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\SoSure;
use AppBundle\Document\ImeiTrait;

/**
 * @group unit
 */
class ImeiTraitTest extends \PHPUnit\Framework\TestCase
{
    use ImeiTrait;

    /**
     * Makes sure the generate random imei function generates valid imeis that are of the correct length and that are
     * under the testing reporting body.
     */
    public function testRandomImei()
    {
        for ($i = 0; $i < 50; $i++) {
            $imei = $this->generateRandomImei();
            $this->assertTrue($this->isImei($imei));
            $this->assertEquals('00', mb_substr($imei, 0, 2));
        }
    }

    /**
     * Make sure that the random imei generator is not generating the same values.
     */
    public function testRandomImeiCollision()
    {
        $this->assertNotEquals($this->generateRandomImei(), $this->generateRandomImei());
    }
}
