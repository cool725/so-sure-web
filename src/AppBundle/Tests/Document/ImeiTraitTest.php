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
     * Makes sure that the generate random imei code produces imei numbers that are random and do not collide.
     * NOTE: there is an extremely small chance that this test will fail but as long as it does not fail repeatedly
     *       that is ok.
     */
    public function testImeiCollision()
    {
        for ($r = 0; $r < 10; $r++) {
            $imeis = [];
            for ($i = 0; $i < 10; $i++) {
                $imeis[] = $this->generateRandomImei();
            }
            for ($a = 0; $a < count($imeis); $a++) {
                for ($b = $a + 1; $b < count($imeis); $b++) {
                    $this->assertNotEquals($imeis[$a], $imeis[$b]);
                }
            }
        }
    }
}
