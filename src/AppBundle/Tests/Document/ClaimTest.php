<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Claim;

/**
 * @group unit
 */
class ClaimTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testSetStatus()
    {
        $claim = new Claim();
        $claim->setType(Claim::TYPE_WARRANTY);
        $claim->setStatus(Claim::STATUS_INREVIEW);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testSetStatusWarranty()
    {
        $claim = new Claim();
        $claim->setType(Claim::TYPE_WARRANTY);
        $claim->setStatus(Claim::STATUS_APPROVED);
    }
}
