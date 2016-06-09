<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\JudoPaymentMethod;

/**
 * @group unit
 */
class JudoPaymentMethodTest extends \PHPUnit_Framework_TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testGetDecodedDeviceDna()
    {
        $judoPaymentMethod = new JudoPaymentMethod();
        $this->assertNull($judoPaymentMethod->getDecodedDeviceDna());

        // @codingStandardsIgnoreStart
        $judoPaymentMethod->setDeviceDna("{\"clientDetails\":{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}}");
        // @codingStandardsIgnoreEnd

        $this->assertTrue(is_array($judoPaymentMethod->getDecodedDeviceDna()));
        $this->assertEquals('Android OS 6.0.1', $judoPaymentMethod->getDecodedDeviceDna()['clientDetails']['OS']);
    }
}
