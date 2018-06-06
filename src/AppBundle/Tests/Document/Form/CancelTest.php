<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Address;
use AppBundle\Document\Form\Cancel;

/**
 * @group unit
 */
class CancelTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testGetEncodedCooloffReason()
    {
        $this->assertEquals(
            'Cooloff - foo',
            Cancel::getEncodedCooloffReason('foo')
        );
    }

    public function testIsEncodedCooloffReason()
    {
        $this->assertTrue(Cancel::isEncodedCooloffReason('Cooloff - foo'));
        $this->assertTrue(Cancel::isEncodedCooloffReason('Cooloff - f'));
        $this->assertFalse(Cancel::isEncodedCooloffReason('Cooloff -'));
    }

    public function testGetDecodedCooloffReason()
    {
        $this->assertEquals(
            'foo',
            Cancel::getDecodedCooloffReason('Cooloff - foo')
        );
    }
}
