<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\SCode;

/**
 * @group unit
 */
class SCodeTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testValidSCode()
    {
        for ($i = 0; $i < 1000; $i++) {
            $scode = new SCode();
            $this->assertTrue(SCode::isValidSCode($scode->getCode()));
        }
    }
}
