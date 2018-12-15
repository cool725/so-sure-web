<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\DirectGroupHandlerClaim;
use AppBundle\Document\Claim;
use AppBundle\Document\PolicyTerms;

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
            '14935 8212660 892',
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
        $this->assertFalse($directGroup->isReplacementRepaired());
        $directGroup->repairSupplier = 'foo';
        $this->assertTrue($directGroup->isReplacementRepaired());
    }

    public function testClaimsExcess()
    {
        $claim = new Claim();

        $directGroup = new DirectGroupHandlerClaim();
        $directGroup->lossType = "Loss - From Pocket";
        $this->assertEquals(Claim::TYPE_LOSS, $directGroup->getClaimType());
        $claim->setType($directGroup->getClaimType(), true);

        $directGroup->excess = 70;
        $claim->setExpectedExcess(PolicyTerms::getLowExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim));

        $directGroup->excess = 150;
        $claim->setExpectedExcess(PolicyTerms::getHighExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim));

        $directGroup->excess = -150;
        $claim->setExpectedExcess(PolicyTerms::getHighExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim, true));
        $this->assertFalse($directGroup->isExcessValueCorrect($claim, false));

        $directGroup->lossType = "Warranty - Audio Fault";
        $this->assertEquals(Claim::TYPE_WARRANTY, $directGroup->getClaimType());
        $claim->setType($directGroup->getClaimType(), true);

        $directGroup->excess = 50;
        $claim->setExpectedExcess(PolicyTerms::getLowExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim));

        $directGroup->excess = 150;
        $claim->setExpectedExcess(PolicyTerms::getHighExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim));

        $directGroup->excess = -150;
        $claim->setExpectedExcess(PolicyTerms::getHighExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim, true));
        $this->assertFalse($directGroup->isExcessValueCorrect($claim, false));

        $directGroup->lossType = "Accidental Damage - Dropped (Away From Home)   ";
        $this->assertEquals(Claim::TYPE_DAMAGE, $directGroup->getClaimType());
        $claim->setType($directGroup->getClaimType(), true);

        $directGroup->excess = 50;
        $claim->setExpectedExcess(PolicyTerms::getLowExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim));

        $directGroup->excess = 150;
        $claim->setExpectedExcess(PolicyTerms::getHighExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim));

        $directGroup->excess = -150;
        $claim->setExpectedExcess(PolicyTerms::getHighExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim, true));
        $this->assertFalse($directGroup->isExcessValueCorrect($claim, false));

        $directGroup->lossType = "Theft - From Pocket";
        $claim->setExpectedExcess(PolicyTerms::getLowExcess());
        $claim->setType($directGroup->getClaimType(), true);

        $directGroup->excess = 70;
        $this->assertTrue($directGroup->isExcessValueCorrect($claim));

        $directGroup->excess = 150;
        $claim->setExpectedExcess(PolicyTerms::getHighExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim));

        $directGroup->excess = -150;
        $claim->setExpectedExcess(PolicyTerms::getHighExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim, true));
        $this->assertFalse($directGroup->isExcessValueCorrect($claim, false));

        $directGroup->lossType = "Extended Warranty - Audio Fault";
        $claim->setType($directGroup->getClaimType(), true);

        $directGroup->excess = 50;
        $claim->setExpectedExcess(PolicyTerms::getLowExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim));

        $directGroup->excess = 150;
        $claim->setExpectedExcess(PolicyTerms::getHighExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim));

        $directGroup->excess = -150;
        $claim->setExpectedExcess(PolicyTerms::getHighExcess());
        $this->assertTrue($directGroup->isExcessValueCorrect($claim, true));
        $this->assertFalse($directGroup->isExcessValueCorrect($claim, false));
    }
}
