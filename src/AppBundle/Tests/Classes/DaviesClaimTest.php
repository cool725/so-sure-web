<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\DaviesClaim;

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
            'So-Sure',
            '320160401000001',
            'Mr John Smith',
            'AB12 3CD',
            '',
            '42461',
            '42430',
            '42794',
            'Damage',
            'Cracked Screen',
            'Closed',
            'Settled',
            'Samsung',
            'S6',
            'XXX',
            '250.49',
            'XX.XX',
            '07123 456789',
            '42461',
            '42461',
            '42461'
        ];
        $davies = new DaviesClaim();
        $davies->fromArray($data);
        $this->assertEquals(new \DateTime('2017-02-28'), $davies->endDate);
        $this->assertEquals(new \DateTime('2016-03-01'), $davies->startDate);
    }
}
