<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\SCode;
use AppBundle\Document\User;

/**
 * @group unit
 */
class SCodeTest extends \PHPUnit\Framework\TestCase
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

    public function testGetNameForUser()
    {
        $user = new User();
        $user->setFirstName("żbieta");
        $user->setLastName("Eżbieta");

        $scode = SCode::getNameForCode($user, SCode::TYPE_STANDARD);
        $this->assertEquals("żeżb", $scode);
    }

    public function testGenerateNamedCode()
    {
        $user = new User();
        $user->setFirstName("żbieta");
        $user->setLastName("Eżbieta");

        $scode = new SCode();
        $scode->setType(SCode::TYPE_STANDARD);
        $scode->generateNamedCode($user, 5);
        $this->assertEquals("żeżb0005", $scode->getCode());
    }

    public function testBadSyntaxGeneratedNamedCode()
    {
        $user = new User();

        $user->setFirstName("ż’b'ieta");
        $user->setLastName("Eżbieta");

        $scode = new SCode();
        $scode->setType(SCode::TYPE_STANDARD);
        $scode->generateNamedCode($user, 5);
        $this->assertEquals("żeżb0005", $scode->getCode());
    }
}
