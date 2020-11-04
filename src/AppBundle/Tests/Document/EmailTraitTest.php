<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\SoSure;
use AppBundle\Document\EmailTrait;

/**
 * @group unit
 */
class EmailTraitTest extends \PHPUnit\Framework\TestCase
{
    use EmailTrait;

    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testIsGmail()
    {
        $this->assertEquals(
            true,
            $this->isGmail('isgmail@gmail.com')
        );
        $this->assertEquals(
            true,
            $this->isGmail('isgmail@googlemail.com')
        );
        $this->assertEquals(
            false,
            $this->isGmail('isnotgmail@outlook.com')
        );
    }
}
