<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Claim;

/**
 * @group unit
 */
class ClaimTest extends \PHPUnit\Framework\TestCase
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
        $this->assertNull($claim->getApprovedDate());
    }

    public function testSetStatusExtended()
    {
        $claim = new Claim();
        $claim->setType(Claim::TYPE_EXTENDED_WARRANTY);
        $claim->setStatus(Claim::STATUS_APPROVED);

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    public function testSetStatusSettled()
    {
        $claim = new Claim();
        $claim->setType(Claim::TYPE_EXTENDED_WARRANTY);
        $claim->setStatus(Claim::STATUS_SETTLED);

        // test is if the above generates an exception
        $this->assertTrue(true);
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
