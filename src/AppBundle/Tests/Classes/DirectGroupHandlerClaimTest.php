<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\DirectGroupHandlerClaim;
use AppBundle\Document\Claim;

/**
 * @group unit
 */
class DirectGroupHandlerClaimTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testFromArrayV1()
    {
        $data = [
            'SO-SURE',
            'TEST/123',
            'ID1',
            'Mr John Smith',
            'AB12 3CD',
            'AMBER',
            '42794',
            '42794',
            'OK',
            'Fine',
            '42794',
            '42794',
            '42794',
            '42794',
            '',
            'Damage',
            'Description',
            'Work',
            'Closed',
            '',
            '42794',
            '',
            '',
            '',
            '42794',
            '42794',
            '42794',
            'Samsung',
            'S6',
            '149358212660892',
            '123 foo st',
            '£250.49',
            '£5.29',
            '£1.30',
            '£250',
            '£0.75',
            '£275',
            '£50',
            '£20',
            '£75',
            '18',
            '£252.54',
            '£232.54',
        ];
        $directGroup = new DirectGroupHandlerClaim();
        $directGroup->fromArray($data, DirectGroupHandlerClaim::COLUMN_COUNT_V1);
        $this->assertEquals(new \DateTime('2017-02-28'), $directGroup->endDate);
        $this->assertEquals(new \DateTime('2017-02-28'), $directGroup->startDate);

        $this->assertEquals(50, $directGroup->reserved);
        $this->assertEquals(75, $directGroup->excess);
        $this->assertEquals(20, $directGroup->handlingFees);
        $this->assertEquals(250.49, $directGroup->phoneReplacementCost);
        $this->assertEquals(1.3, $directGroup->accessories);
        $this->assertEquals(0.75, $directGroup->unauthorizedCalls);
        $this->assertEquals(197.54, $directGroup->totalIncurred);
        $this->assertEquals(177.54, $directGroup->getIncurred());
        $this->assertEquals($directGroup->getExpectedIncurred(), $directGroup->getIncurred());

        $this->assertEquals('149358212660892', $directGroup->replacementImei);
        $this->assertEquals('Samsung', $directGroup->replacementMake);
        $this->assertEquals('S6', $directGroup->replacementModel);
        $this->assertEquals('Samsung S6', $directGroup->getReplacementPhoneDetails());
    }

    /**
     * @expectedException \Exception
     */
    public function testFromArrayInvalidNumColumns()
    {
        $data = [
            'SO-SURE',
            '320160401000001',
        ];
        $directGroup = new DirectGroupHandlerClaim();
        $directGroup->fromArray($data, DirectGroupHandlerClaim::COLUMN_COUNT_V1);
    }

    public function testFromArrayInvalidClient()
    {
        $data = [
            'Invalid Client',
            'TEST/123',
            'ID1',
            'Mr John Smith',
            'AB12 3CD',
            'AMBER',
            '42794',
            '42794',
            'OK',
            'Fine',
            '42794',
            '42794',
            '42794',
            '42794',
            '',
            'Damage',
            'Description',
            'Work',
            'Closed',
            '',
            '42794',
            '',
            '',
            '',
            '42794',
            '42794',
            '42794',
            'Samsung',
            'S6',
            '149358212660892',
            '123 foo st',
            '£250.49',
            '£5.29',
            '£1.30',
            '£250',
            '£0.75',
            '£275',
            '£50',
            '£220',
            '£1.08',
            '18',
            '£1.08',
            '£1.08',
        ];
        $directGroup = new DirectGroupHandlerClaim();
        $this->assertNull($directGroup->fromArray($data, DirectGroupHandlerClaim::COLUMN_COUNT_V1));
        $this->assertNull($directGroup->claimNumber);
    }

    /**
     * @expectedException \Exception
     */
    public function testFromArrayDateEarlyDG()
    {
        $data = [
            'SO-SURE',
            'TEST/123',
            'ID1',
            'Mr John Smith',
            'AB12 3CD',
            'AMBER',
            '42461',
            '42794',
            'OK',
            'Fine',
            '42794',
            '42794',
            '42794',
            '42794',
            '',
            'Damage',
            'Description',
            'Work',
            'Closed',
            '',
            '42794',
            '',
            '',
            '',
            '42794',
            '42794',
            '42794',
            'Samsung',
            'S6',
            '149358212660892',
            '123 foo st',
            '£250.49',
            '£5.29',
            '£1.30',
            '£250',
            '£0.75',
            '£275',
            '£50',
            '£220',
            '£1.08',
            '18',
            '£1.08',
            '£1.08',
        ];
        $directGroup = new DirectGroupHandlerClaim();
        $directGroup->fromArray($data, DirectGroupHandlerClaim::COLUMN_COUNT_V1);
    }

    public function testFromArrayReceivedDateEarly()
    {
        $data = [
            'Invalid Client',
            'TEST/123',
            'ID1',
            'Mr John Smith',
            'AB12 3CD',
            'AMBER',
            '42794',
            '42794',
            'OK',
            'Fine',
            '42794',
            '42794',
            '42794',
            '42794',
            '',
            'Damage',
            'Description',
            'Work',
            'Closed',
            '',
            '42794',
            '',
            '',
            '',
            '42794',
            '42794',
            '40000',
            'Samsung',
            'S6',
            '149358212660892',
            '123 foo st',
            '£250.49',
            '£5.29',
            '£1.30',
            '£250',
            '£0.75',
            '£275',
            '£50',
            '£220',
            '£1.08',
            '18',
            '£1.08',
            '£1.08',
        ];
        $directGroup = new DirectGroupHandlerClaim();
        $directGroup->fromArray($data, DirectGroupHandlerClaim::COLUMN_COUNT_V1);
        $this->assertNull($directGroup->replacementReceivedDate);
    }

    /**
     * @expectedException \Exception
     */
    public function testFromArrayDateLate()
    {
        $data = [
            'SO-SURE',
            'TEST/123',
            'ID1',
            'Mr John Smith',
            'AB12 3CD',
            'AMBER',
            '42461',
            '42794',
            'OK',
            'Fine',
            '42794',
            '42794',
            '42794',
            '42794',
            '',
            'Damage',
            'Description',
            'Work',
            'Closed',
            '',
            '42794',
            '',
            '',
            '',
            '42794',
            '42794',
            '42794',
            'Samsung',
            'S6',
            '149358212660892',
            '123 foo st',
            '£250.49',
            '£5.29',
            '£1.30',
            '£250',
            '£0.75',
            '£275',
            '£50',
            '£220',
            '£1.08',
            '18',
            '£1.08',
            '£1.08',
        ];
        $directGroup = new DirectGroupHandlerClaim();
        $directGroup->fromArray($data, DirectGroupHandlerClaim::COLUMN_COUNT_V1);
    }

    public function testReplacementRepair()
    {
        $directGroup = new DirectGroupHandlerClaim();
        $this->assertFalse($directGroup->checkReplacementRepaired());
        $directGroup->repairSupplier = 'foo';
        $this->assertTrue($directGroup->checkReplacementRepaired());
    }

    public function testClaimsExcess()
    {
        $directGroup = new DirectGroupHandlerClaim();
        $directGroup->lossType = "Loss - From Pocket";
        $directGroup->excess = 70;
        $this->assertEquals(Claim::TYPE_LOSS, $directGroup->getClaimType());
        $this->assertTrue($directGroup->isExcessValueCorrect(false, false));
        $this->assertTrue($directGroup->isExcessValueCorrect(true, false));
        $this->assertTrue($directGroup->isExcessValueCorrect(true, true));
        $this->assertFalse($directGroup->isExcessValueCorrect(false, true));
        $directGroup->excess = 150;
        $this->assertTrue($directGroup->isExcessValueCorrect(false, true));
        $directGroup->excess = -150;
        $this->assertTrue($directGroup->isExcessValueCorrect(false, true, true));
        $this->assertFalse($directGroup->isExcessValueCorrect(false, true, false));

        $directGroup->lossType = "Warranty - Audio Fault";
        $directGroup->excess = 50;
        $this->assertEquals(Claim::TYPE_WARRANTY, $directGroup->getClaimType());
        $this->assertTrue($directGroup->isExcessValueCorrect(false, false));
        $this->assertTrue($directGroup->isExcessValueCorrect(true, false));
        $this->assertTrue($directGroup->isExcessValueCorrect(true, true));
        $this->assertFalse($directGroup->isExcessValueCorrect(false, true));
        $directGroup->excess = 150;
        $this->assertTrue($directGroup->isExcessValueCorrect(false, true));
        $directGroup->excess = -150;
        $this->assertTrue($directGroup->isExcessValueCorrect(false, true, true));
        $this->assertFalse($directGroup->isExcessValueCorrect(false, true, false));

        $directGroup->lossType = "Accidental Damage - Dropped (Away From Home)   ";
        $directGroup->excess = 50;
        $this->assertEquals(Claim::TYPE_DAMAGE, $directGroup->getClaimType());
        $this->assertTrue($directGroup->isExcessValueCorrect(false, false));
        $this->assertTrue($directGroup->isExcessValueCorrect(true, false));
        $this->assertTrue($directGroup->isExcessValueCorrect(true, true));
        $this->assertFalse($directGroup->isExcessValueCorrect(false, true));
        $directGroup->excess = 150;
        $this->assertTrue($directGroup->isExcessValueCorrect(false, true));
        $directGroup->excess = -150;
        $this->assertTrue($directGroup->isExcessValueCorrect(false, true, true));
        $this->assertFalse($directGroup->isExcessValueCorrect(false, true, false));

        $directGroup->lossType = "Theft - From Pocket";
        $directGroup->excess = 70;
        $this->assertEquals(Claim::TYPE_THEFT, $directGroup->getClaimType());
        $this->assertTrue($directGroup->isExcessValueCorrect(false, false));
        $this->assertTrue($directGroup->isExcessValueCorrect(true, false));
        $this->assertTrue($directGroup->isExcessValueCorrect(true, true));
        $this->assertFalse($directGroup->isExcessValueCorrect(false, true));
        $directGroup->excess = 150;
        $this->assertTrue($directGroup->isExcessValueCorrect(false, true));
        $directGroup->excess = -150;
        $this->assertTrue($directGroup->isExcessValueCorrect(false, true, true));
        $this->assertFalse($directGroup->isExcessValueCorrect(false, true, false));

        $directGroup->lossType = "Extended Warranty - Audio Fault";
        $directGroup->excess = 50;
        $this->assertEquals(Claim::TYPE_EXTENDED_WARRANTY, $directGroup->getClaimType());
        $this->assertTrue($directGroup->isExcessValueCorrect(false, false));
        $this->assertTrue($directGroup->isExcessValueCorrect(true, false));
        $this->assertTrue($directGroup->isExcessValueCorrect(true, true));
        $this->assertFalse($directGroup->isExcessValueCorrect(false, true));
        $directGroup->excess = 150;
        $this->assertTrue($directGroup->isExcessValueCorrect(false, true));
        $directGroup->excess = -150;
        $this->assertTrue($directGroup->isExcessValueCorrect(false, true, true));
        $this->assertFalse($directGroup->isExcessValueCorrect(false, true, false));
    }
}
