<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Claim;
use DateTime;
use Symfony\Bridge\PhpUnit\ClockMock;

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

    /**
     * @group time-sensitive
     */
    /*public function testGetStatusLastUpdatedOnChange()       #The test-structure is incompatible with ClockMocking
    {
        ClockMock::register(self::class);
        ClockMock::register(Claim::class);
        ClockMock::withClockMock(true);

        $now = DateTime::createFromFormat('U', (string) time());

        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $this->assertEquals($claim->getStatusLastUpdated(), $now, __LINE__);

        sleep(3);

        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $this->assertEquals($claim->getStatusLastUpdated(), $now, 'Expected the StatusLastUpdated to be unchanged');
        sleep(4);
        $this->assertEquals($claim->getStatusLastUpdated(), $now, 'Expected StatusLastUpdated to still be unchanged');

        $later = DateTime::createFromFormat('U', (string) time());
        $claim->setStatus(Claim::STATUS_SETTLED);

        $this->assertEquals(
            (string) $later->format('U'),
            (string) $claim->getStatusLastUpdated()->format('U'),
            'Expected StatusLastUpdated to have been updated'
        );
    }*/
}
