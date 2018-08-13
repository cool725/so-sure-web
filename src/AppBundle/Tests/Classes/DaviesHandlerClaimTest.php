<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\DaviesHandlerClaim;
use AppBundle\Document\Claim;

/**
 * @group unit
 */
class DaviesHandlerClaimTest extends \PHPUnit\Framework\TestCase
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
            'So-Sure -Mobile',
            '320160401000001',
            'Mr John Smith',
            'AB12 3CD',
            '42794',
            '42794',
            '42794',
            'Damage',
            'Cracked Screen',
            'Work',
            'Closed',
            'Settled',
            '42794',
            'Samsung',
            'S6',
            '149358212660892',
            '42794',
            '£250.49',
            '£5.29',
            '£1.30',
            '£250',
            '£0.75',
            '£275',
            '£50',
            '£220',
            '£1.08',
            '07123 456789',
            '42794',
            '42794',
            '42794',
            '123 The Street, Town, City, Postcode'
        ];
        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V1);
        $this->assertEquals(new \DateTime('2017-02-28'), $davies->endDate);
        $this->assertEquals(new \DateTime('2017-02-28'), $davies->startDate);

        $this->assertEquals(1.08, $davies->reciperoFee);
        $this->assertEquals(220, $davies->reserved);
        $this->assertEquals(50, $davies->excess);
        $this->assertEquals(275, $davies->handlingFees);
        $this->assertEquals(0.75, $davies->transactionFees);
        $this->assertEquals(250, $davies->phoneReplacementCost);
        $this->assertEquals(1.3, $davies->accessories);
        $this->assertEquals(5.29, $davies->unauthorizedCalls);
        $this->assertEquals(250.49, $davies->incurred);

        $this->assertEquals('149358212660892', $davies->replacementImei);
        $this->assertEquals('Samsung', $davies->replacementMake);
        $this->assertEquals('S6', $davies->replacementModel);
        $this->assertEquals('Samsung S6', $davies->getReplacementPhoneDetails());
    }

    /**
     * @expectedException \Exception
     */
    public function testFromArrayV1Expired()
    {
        $data = [
            'So-Sure -Mobile',
            '320160401000001',
            'Mr John Smith',
            'AB12 3CD',
            '42794',
            '42430',
            '42794',
            'Damage',
            'Cracked Screen',
            'Work',
            'Closed',
            'Settled',
            '42461',
            'Samsung',
            'S6',
            '149358212660892',
            '42461',
            '£250.49',
            '£5.29',
            '£1.30',
            '£250',
            '£0.75',
            '£275',
            '£50',
            '£220',
            '£1.08',
            '07123 456789',
            '42461',
            '42461',
            '42461',
            '123 The Street, Town, City, Postcode'
        ];
        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V1);
    }

    public function testFromArrayV6()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accidental Damage - Dropped In Water',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Open',
            '',
            'TBC',
            'Samsung',
            'Galaxy S7 edge (32 GB)',
            '149358212660892',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V6);
        $this->assertEquals(new \DateTime('2017-11-02'), $davies->endDate);
        $this->assertEquals(new \DateTime('2016-11-03'), $davies->startDate);

        $this->assertEquals(1.08, $davies->reciperoFee);
        $this->assertEquals(700.26, $davies->reserved);
        $this->assertEquals(50, $davies->excess);
        $this->assertEquals(15, $davies->handlingFees);
        $this->assertEquals(0.06, $davies->transactionFees);
        $this->assertEquals(0.01, $davies->phoneReplacementCost);
        $this->assertEquals(0.02, $davies->accessories);
        $this->assertEquals(0.04, $davies->unauthorizedCalls);
        $this->assertEquals(-35, $davies->incurred);

        $this->assertEquals('149358212660892', $davies->replacementImei);
        $this->assertEquals('Samsung', $davies->replacementMake);
        $this->assertEquals('Galaxy S7 edge (32 GB)', $davies->replacementModel);
        $this->assertEquals('Samsung Galaxy S7 edge (32 GB)', $davies->getReplacementPhoneDetails());
    }

    public function testFromArrayV8()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accidental Damage - Dropped In Water',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Open',
            '',
            'TBC',
            'Samsung',
            'Galaxy S7 edge (32 GB)',
            '149358212660892',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26',
            'red',
            '10',
            'Ok',
            'Suspicious'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V8);
        $this->assertEquals(new \DateTime('2017-11-02'), $davies->endDate);
        $this->assertEquals(new \DateTime('2016-11-03'), $davies->startDate);

        $this->assertEquals(1.08, $davies->reciperoFee);
        $this->assertEquals(700.26, $davies->reserved);
        $this->assertEquals(50, $davies->excess);
        $this->assertEquals(15, $davies->handlingFees);
        $this->assertEquals(0.06, $davies->transactionFees);
        $this->assertEquals(0.01, $davies->phoneReplacementCost);
        $this->assertEquals(0.02, $davies->accessories);
        $this->assertEquals(0.04, $davies->unauthorizedCalls);
        $this->assertEquals(-35, $davies->incurred);

        $this->assertEquals('149358212660892', $davies->replacementImei);
        $this->assertEquals('Samsung', $davies->replacementMake);
        $this->assertEquals('Galaxy S7 edge (32 GB)', $davies->replacementModel);
        $this->assertEquals('Samsung Galaxy S7 edge (32 GB)', $davies->getReplacementPhoneDetails());
        $this->assertEquals('red', $davies->risk);
        $this->assertFalse($davies->initialSuspicion);
        $this->assertTrue($davies->finalSuspicion);
    }

    public function testFromArrayV8UnobtaintableImei()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accidental Damage - Dropped In Water',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Open',
            '',
            'TBC',
            'Samsung',
            'Galaxy S7 edge (32 GB)',
            'Unable to obtain',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26',
            'red',
            '10',
            'Ok',
            'Suspicious'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V8);
        $this->assertEquals(new \DateTime('2017-11-02'), $davies->endDate);
        $this->assertEquals(new \DateTime('2016-11-03'), $davies->startDate);

        $this->assertEquals(1.08, $davies->reciperoFee);
        $this->assertEquals(700.26, $davies->reserved);
        $this->assertEquals(50, $davies->excess);
        $this->assertEquals(15, $davies->handlingFees);
        $this->assertEquals(0.06, $davies->transactionFees);
        $this->assertEquals(0.01, $davies->phoneReplacementCost);
        $this->assertEquals(0.02, $davies->accessories);
        $this->assertEquals(0.04, $davies->unauthorizedCalls);
        $this->assertEquals(-35, $davies->incurred);

        $this->assertNull($davies->replacementImei);
        $this->assertEquals(1, count($davies->unobtainableFields));
        $this->assertEquals('replacementImei', $davies->unobtainableFields[0]);
        $this->assertEquals('Samsung', $davies->replacementMake);
        $this->assertEquals('Galaxy S7 edge (32 GB)', $davies->replacementModel);
        $this->assertEquals('Samsung Galaxy S7 edge (32 GB)', $davies->getReplacementPhoneDetails());
        $this->assertEquals('red', $davies->risk);
        $this->assertFalse($davies->initialSuspicion);
        $this->assertTrue($davies->finalSuspicion);
    }

    public function testMIStatusSpace()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accidental Damage - Dropped In Water',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Open',
            'Claimant Correspondence',
            'TBC',
            'Samsung',
            'Galaxy S7 edge (32 GB)',
            '149358212660892',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $this->assertTrue($davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V6));
        $this->assertEquals($davies::MISTATUS_CLAIMANT_CORRESPONDENCE, $davies->miStatus);
    }

    public function testMIStatusError()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accidental Damage - Dropped In Water',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Open',
            'Error',
            'TBC',
            'Samsung',
            'Galaxy S7 edge (32 GB)',
            '149358212660892',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $this->assertTrue($davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V6));
        $this->assertEquals($davies::MISTATUS_ERROR, $davies->miStatus);
        $this->assertTrue($davies->hasError());
    }

    public function testMIStatusDMSError()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accidental Damage - Dropped In Water',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Open',
            'DMS Error',
            'TBC',
            'Samsung',
            'Galaxy S7 edge (32 GB)',
            '149358212660892',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $this->assertTrue($davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V6));
        $this->assertEquals($davies::MISTATUS_DMS_ERROR, $davies->miStatus);
        $this->assertTrue($davies->hasError());
    }

    /**
     * @expectedException \Exception
     */
    public function testInvalidImei()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accidental Damage - Dropped In Water',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Open',
            'DMS Error',
            'TBC',
            'Samsung',
            'Galaxy S7 edge (32 GB)',
            '4935821266089',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $this->assertTrue($davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V6));
    }

    public function testRepairedImei()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accidental Damage - Dropped In Water',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Open',
            '',
            'TBC',
            'Samsung',
            'NA - repaired',
            'NA - repaired',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26',
            'red',
            '10',
            'Ok',
            'Suspicious'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V8);
        $this->assertEquals(new \DateTime('2017-11-02'), $davies->endDate);
        $this->assertEquals(new \DateTime('2016-11-03'), $davies->startDate);

        $this->assertEquals(1.08, $davies->reciperoFee);
        $this->assertEquals(700.26, $davies->reserved);
        $this->assertEquals(50, $davies->excess);
        $this->assertEquals(15, $davies->handlingFees);
        $this->assertEquals(0.06, $davies->transactionFees);
        $this->assertEquals(0.01, $davies->phoneReplacementCost);
        $this->assertEquals(0.02, $davies->accessories);
        $this->assertEquals(0.04, $davies->unauthorizedCalls);
        $this->assertEquals(-35, $davies->incurred);

        $this->assertNull($davies->replacementImei);
        $this->assertEquals(0, count($davies->unobtainableFields));
        $this->assertTrue($davies->isReplacementRepaired());
        $this->assertNull($davies->replacementImei);
        $this->assertEquals('Samsung', $davies->replacementMake);
        $this->assertEquals('NA - repaired', $davies->replacementModel);
        $this->assertEquals('red', $davies->risk);
        $this->assertFalse($davies->initialSuspicion);
        $this->assertTrue($davies->finalSuspicion);
    }

    /**
     * @expectedException \Exception
     */
    public function testFromArrayInvalidNumColumns()
    {
        $data = [
            'So-Sure -Mobile',
            '320160401000001',
        ];
        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V1);
    }

    public function testFromArrayInvalidClient()
    {
        $data = [
            'Invalid Client',
            '320160401000001',
            'Mr John Smith',
            'AB12 3CD',
            '42461',
            '42430',
            '42794',
            'Damage',
            'Cracked Screen',
            'Work',
            'Closed',
            'Settled',
            '345678',
            'Samsung',
            'S6',
            '149358212660892',
            '345678',
            '250.49',
            '5.29',
            '1.30',
            '250',
            '0.75',
            '275',
            '50',
            '220',
            '1.08',
            '07123 456789',
            '42461',
            '42461',
            '42461',
            '123 The Street, Town, City, Postcode'
        ];
        $davies = new DaviesHandlerClaim();
        $this->assertNull($davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V1));
        $this->assertNull($davies->claimNumber);
    }

    /**
     * @expectedException \Exception
     */
    public function testFromArrayDateEarly()
    {
        $data = [
            'So-Sure -Mobile',
            '320160401000001',
            'Mr John Smith',
            'AB12 3CD',
            '42461',
            '42430',
            '42794',
            'Damage',
            'Cracked Screen',
            'Work',
            'Closed',
            'Settled',
            '42461',
            'Samsung',
            'S6',
            '149358212660892',
            '42461',
            '250.49',
            '5.29',
            '1.30',
            '250',
            '0.75',
            '275',
            '50',
            '220',
            '1.08',
            '07123 456789',
            '5',
            '42461',
            '42461',
            '123 The Street, Town, City, Postcode'
        ];
        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V1);
    }

    public function testFromArrayReceivedDateEarly()
    {
        $data = [
            'So-Sure -Mobile',
            '320160401000001',
            'Mr John Smith',
            'AB12 3CD',
            '42794',
            '42794',
            '42794',
            'Damage',
            'Cracked Screen',
            'Work',
            'Closed',
            'Settled',
            '42794',
            'Samsung',
            'S6',
            '149358212660892',
            '40000',
            '£250.49',
            '£5.29',
            '£1.30',
            '£250',
            '£0.75',
            '£275',
            '£50',
            '£220',
            '£1.08',
            '07123 456789',
            '42794',
            '42794',
            '42794',
            '123 The Street, Town, City, Postcode'
        ];
        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V1);
        $this->assertNull($davies->replacementReceivedDate);
    }

    /**
     * @expectedException \Exception
     */
    public function testFromArrayDateLate()
    {
        $data = [
            'So-Sure -Mobile',
            '320160401000001',
            'Mr John Smith',
            'AB12 3CD',
            '42461',
            '42430',
            '42794',
            'Damage',
            'Cracked Screen',
            'Work',
            'Closed',
            'Settled',
            '42461',
            'Samsung',
            'S6',
            '149358212660892',
            '42461',
            '250.49',
            '5.29',
            '1.30',
            '250',
            '0.75',
            '275',
            '50',
            '220',
            '1.08',
            '07123 456789',
            '52461',
            '42461',
            '42461',
            '123 The Street, Town, City, Postcode'
        ];
        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V1);
    }

    public function testClaimsType()
    {
        $davies = new DaviesHandlerClaim();
        $davies->lossType = "Loss - From Pocket";
        $this->assertEquals(Claim::TYPE_LOSS, $davies->getClaimType());
        $this->assertEquals(70, $davies->getExpectedExcess(false, false));
        $this->assertEquals(70, $davies->getExpectedExcess(true, false));
        $this->assertEquals(70, $davies->getExpectedExcess(true, true));
        $this->assertEquals(150, $davies->getExpectedExcess(false, true));

        $davies->lossType = "Warranty - Audio Fault";
        $this->assertEquals(Claim::TYPE_WARRANTY, $davies->getClaimType());
        $this->assertEquals(50, $davies->getExpectedExcess(false, false));
        $this->assertEquals(50, $davies->getExpectedExcess(true, false));
        $this->assertEquals(50, $davies->getExpectedExcess(true, true));
        $this->assertEquals(150, $davies->getExpectedExcess(false, true));

        $davies->lossType = "Accidental Damage - Dropped (Away From Home)   ";
        $this->assertEquals(Claim::TYPE_DAMAGE, $davies->getClaimType());
        $this->assertEquals(50, $davies->getExpectedExcess(false, false));
        $this->assertEquals(50, $davies->getExpectedExcess(true, false));
        $this->assertEquals(50, $davies->getExpectedExcess(true, true));
        $this->assertEquals(150, $davies->getExpectedExcess(false, true));

        $davies->lossType = "Theft - From Pocket";
        $this->assertEquals(Claim::TYPE_THEFT, $davies->getClaimType());
        $this->assertEquals(70, $davies->getExpectedExcess(false, false));
        $this->assertEquals(70, $davies->getExpectedExcess(true, false));
        $this->assertEquals(70, $davies->getExpectedExcess(true, true));
        $this->assertEquals(150, $davies->getExpectedExcess(false, true));

        $davies->lossType = "Extended Warranty - Audio Fault";
        $this->assertEquals(Claim::TYPE_EXTENDED_WARRANTY, $davies->getClaimType());
        $this->assertEquals(50, $davies->getExpectedExcess(false, false));
        $this->assertEquals(50, $davies->getExpectedExcess(true, false));
        $this->assertEquals(50, $davies->getExpectedExcess(true, true));
        $this->assertEquals(150, $davies->getExpectedExcess(false, true));
    }

    public function testClaimsExcess()
    {
        $davies = new DaviesHandlerClaim();
        $davies->lossType = "Loss - From Pocket";
        $davies->excess = 70;
        $this->assertEquals(Claim::TYPE_LOSS, $davies->getClaimType());
        $this->assertTrue($davies->isExcessValueCorrect(false, false));
        $this->assertTrue($davies->isExcessValueCorrect(true, false));
        $this->assertTrue($davies->isExcessValueCorrect(true, true));
        $this->assertFalse($davies->isExcessValueCorrect(false, true));
        $davies->excess = 150;
        $this->assertTrue($davies->isExcessValueCorrect(false, true));
        $davies->excess = -150;
        $this->assertTrue($davies->isExcessValueCorrect(false, true, true));
        $this->assertFalse($davies->isExcessValueCorrect(false, true, false));

        $davies->lossType = "Warranty - Audio Fault";
        $davies->excess = 50;
        $this->assertEquals(Claim::TYPE_WARRANTY, $davies->getClaimType());
        $this->assertTrue($davies->isExcessValueCorrect(false, false));
        $this->assertTrue($davies->isExcessValueCorrect(true, false));
        $this->assertTrue($davies->isExcessValueCorrect(true, true));
        $this->assertFalse($davies->isExcessValueCorrect(false, true));
        $davies->excess = 150;
        $this->assertTrue($davies->isExcessValueCorrect(false, true));
        $davies->excess = -150;
        $this->assertTrue($davies->isExcessValueCorrect(false, true, true));
        $this->assertFalse($davies->isExcessValueCorrect(false, true, false));

        $davies->lossType = "Accidental Damage - Dropped (Away From Home)   ";
        $davies->excess = 50;
        $this->assertEquals(Claim::TYPE_DAMAGE, $davies->getClaimType());
        $this->assertTrue($davies->isExcessValueCorrect(false, false));
        $this->assertTrue($davies->isExcessValueCorrect(true, false));
        $this->assertTrue($davies->isExcessValueCorrect(true, true));
        $this->assertFalse($davies->isExcessValueCorrect(false, true));
        $davies->excess = 150;
        $this->assertTrue($davies->isExcessValueCorrect(false, true));
        $davies->excess = -150;
        $this->assertTrue($davies->isExcessValueCorrect(false, true, true));
        $this->assertFalse($davies->isExcessValueCorrect(false, true, false));

        $davies->lossType = "Theft - From Pocket";
        $davies->excess = 70;
        $this->assertEquals(Claim::TYPE_THEFT, $davies->getClaimType());
        $this->assertTrue($davies->isExcessValueCorrect(false, false));
        $this->assertTrue($davies->isExcessValueCorrect(true, false));
        $this->assertTrue($davies->isExcessValueCorrect(true, true));
        $this->assertFalse($davies->isExcessValueCorrect(false, true));
        $davies->excess = 150;
        $this->assertTrue($davies->isExcessValueCorrect(false, true));
        $davies->excess = -150;
        $this->assertTrue($davies->isExcessValueCorrect(false, true, true));
        $this->assertFalse($davies->isExcessValueCorrect(false, true, false));

        $davies->lossType = "Extended Warranty - Audio Fault";
        $davies->excess = 50;
        $this->assertEquals(Claim::TYPE_EXTENDED_WARRANTY, $davies->getClaimType());
        $this->assertTrue($davies->isExcessValueCorrect(false, false));
        $this->assertTrue($davies->isExcessValueCorrect(true, false));
        $this->assertTrue($davies->isExcessValueCorrect(true, true));
        $this->assertFalse($davies->isExcessValueCorrect(false, true));
        $davies->excess = 150;
        $this->assertTrue($davies->isExcessValueCorrect(false, true));
        $davies->excess = -150;
        $this->assertTrue($davies->isExcessValueCorrect(false, true, true));
        $this->assertFalse($davies->isExcessValueCorrect(false, true, false));
    }

    /**
     * @expectedException \Exception
     */
    public function testFromArrayV6InvalidStatus()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accidental Damage - Dropped In Water',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Unknown',
            '',
            'TBC',
            'Samsung',
            'Galaxy S7 edge (32 GB)',
            '149358212660892',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V6);
    }

    /**
     * @expectedException \Exception
     */
    public function testFromArrayV6InvalidMIStatus()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accidental Damage - Dropped In Water',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Closed',
            'foo',
            'TBC',
            'Samsung',
            'Galaxy S7 edge (32 GB)',
            '149358212660892',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V6);
    }

    /**
     * @expectedException \Exception
     */
    public function testFromArrayV6MisingClaimType()
    {
        // @codingStandardsIgnoreStart
        $data = [
            'So-Sure -Mobile',
            '320170109000912',
            'Mr Steve Morrison',
            'TR11 2HR',
            '21/12/2016',
            '03/11/2016',
            '02/11/2017',
            'Accident',
            'The insured went out for lunch and fell off his motorbike. The insureds phone was in his pocket and this got damage. The screen has got lines across and the back of the phone has completely shattered.',
            'In Street',
            'Open',
            '',
            'TBC',
            'Samsung',
            'Galaxy S7 edge (32 GB)',
            '149358212660892',
            'TBC',
            '£0.01',
            '£700.00',
            '£0.02',
            '£0.03',
            '£0.04',
            '£0.05',
            '£1.08',
            '£0.06',
            '£0.26',
            '£700.26',
            '-£35.00',
            '£15.00',
            '£50.00',
            'Mob/2016/5500048',
            '09/01/2017',
            '09/01/2017',
            'TBC',
            'Discount Tyres Redruth Ltd, School Lane, TR15 2DU',
            '£700.26'
        ];
        // @codingStandardsIgnoreEnd

        $davies = new DaviesHandlerClaim();
        $davies->fromArray($data, DaviesHandlerClaim::COLUMN_COUNT_V6);
    }
}
