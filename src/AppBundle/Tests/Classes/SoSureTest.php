<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\SoSure;

/**
 * @group unit
 */
class SoSureTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function hasSoSureEmail()
    {
        $this->assertTrue(SoSure::hasSoSureEmail('foo@so-Sure.com'));
        $this->assertTrue(SoSure::hasSoSureEmail('foo@so-sure.com'));
        $this->assertFalse(SoSure::hasSoSureEmail('foo@so_sure.com'));
    }
}
