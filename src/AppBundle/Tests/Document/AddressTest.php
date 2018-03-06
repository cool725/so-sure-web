<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Address;

/**
 * @group unit
 */
class AddressTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testValidType()
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);

        // test is if the above generates an exception
        $this->assertTrue(true);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testInValidType()
    {
        $address = new Address();
        $address->setType('invalid');
    }
}
