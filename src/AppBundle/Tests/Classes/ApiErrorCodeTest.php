<?php

namespace AppBundle\Tests\Classes;

use AppBundle\Classes\ApiErrorCode;

/**
 * @group unit
 */
class ApiErrorCodeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Tests that error messages are generated correctly.
     */
    public function testErrorMessage()
    {
        $this->assertEquals(
            "location:<600>\nbingbingwahoo",
            ApiErrorCode::errorMessage("location", ApiErrorCode::EX_UNKNOWN, "bingbingwahoo")
        );
    }
}
