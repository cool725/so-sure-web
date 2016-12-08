<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\DaviesClaim;
use AppBundle\Document\Claim;

/**
 * @group unit
 */
class DaviesClaimTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testFromArray()
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
            '345678',
            'Samsung',
            'S6',
            '351236666677777',
            '345678',
            '250.49',
            '5.29',
            '1.30',
            '250',
            '0.75',
            '275',
            '50',
            '220',
            '07123 456789',
            '42461',
            '42461',
            '42461',
            '123 The Street, Town, City, Postcode'
        ];
        $davies = new DaviesClaim();
        $davies->fromArray($data);
        $this->assertEquals(new \DateTime('2017-02-28'), $davies->endDate);
        $this->assertEquals(new \DateTime('2016-03-01'), $davies->startDate);
    }

    public function testClaimsType()
    {
        $davies = new DaviesClaim();
        $davies->lossType = "Loss - From Pocket";
        $this->assertEquals(Claim::TYPE_LOSS, $davies->getClaimType());

        $davies->lossType = "Warranty - Audio Fault";
        $this->assertEquals(Claim::TYPE_WARRANTY, $davies->getClaimType());

        $davies->lossType = "Accidental Damage - Dropped (Away From Home)   ";
        $this->assertEquals(Claim::TYPE_DAMAGE, $davies->getClaimType());

        $davies->lossType = "Theft - From Pocket";
        $this->assertEquals(Claim::TYPE_THEFT, $davies->getClaimType());
    }
}
