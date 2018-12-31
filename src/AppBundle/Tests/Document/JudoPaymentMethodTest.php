<?php

namespace AppBundle\Tests\Document;

use AppBundle\Classes\SoSure;
use AppBundle\Document\JudoPaymentMethod;

/**
 * @group unit
 */
class JudoPaymentMethodTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass()
    {
    }

    public function tearDown()
    {
    }

    public function testGetAndroidDecodedDeviceDna()
    {
        $judoPaymentMethod = new JudoPaymentMethod();
        $this->assertNull($judoPaymentMethod->getDecodedDeviceDna());

        // @codingStandardsIgnoreStart
        $judoPaymentMethod->setDeviceDna("{\"OS\":\"Android OS 6.0.1\",\"kDeviceID\":\"da471ee402afeb24\",\"vDeviceID\":\"03bd3e3c-66d0-4e46-9369-cc45bb078f5f\",\"culture_locale\":\"en_GB\",\"deviceModel\":\"Nexus 5\",\"countryCode\":\"826\"}");
        // @codingStandardsIgnoreEnd

        $this->assertTrue(is_array($judoPaymentMethod->getDecodedDeviceDna()));
        $this->assertEquals('Android OS 6.0.1', $judoPaymentMethod->getDecodedDeviceDna()['OS']);
    }

    public function testGetAppleDecodedDeviceDna()
    {
        $judoPaymentMethod = new JudoPaymentMethod();
        $this->assertNull($judoPaymentMethod->getDecodedDeviceDna());

        // @codingStandardsIgnoreStart
        $judoPaymentMethod->setDeviceDna("{\n  \"culture_locale\" : \"en_GB\",\n  \"deviceModel\" : \"iPhone\",\n  \"kDeviceID\" : \"E720B797-31DD-4F4B-A982-AE5364B14143\",\n  \"os\" : \"iPhone OS 9.3.2\",\n  \"vDeviceID\" : \"8A466695-7143-4BB3-81CB-CC8E15766C7D\",\n  \"networkName\" : \"vodafone UK\"\n}");
        // @codingStandardsIgnoreEnd

        $this->assertTrue(is_array($judoPaymentMethod->getDecodedDeviceDna()));
        $this->assertEquals('iPhone OS 9.3.2', $judoPaymentMethod->getDecodedDeviceDna()['os']);
    }

    public function testCardDigits()
    {
        $judoPaymentMethod = new JudoPaymentMethod();
        $judoPaymentMethod->addCardToken('a', "{\"cardLastfour\":\"7954\",\"endDate\":\"0518\",\"cardType\":11}");
        $this->assertEquals("7954", $judoPaymentMethod->getCardLastFour());
        $this->assertEquals("0518", $judoPaymentMethod->getCardEndDate());
        $this->assertEquals("Visa Debit", $judoPaymentMethod->getCardType());
    }

    public function testIsCardExpiredPreApr18()
    {
        $judoPaymentMethod = new JudoPaymentMethod();
        $judoPaymentMethod->addCardToken('a', "{\"cardLastfour\":\"7954\",\"endDate\":\"0118\",\"cardType\":11}");
        $this->assertEquals(
            new \DateTime('2018-02-01 00:00:00'),
            $judoPaymentMethod->getCardEndDateAsDate()
        );
        $this->assertFalse($judoPaymentMethod->isCardExpired(new \DateTime('2018-01-31 00:00:00')));
        $this->assertTrue($judoPaymentMethod->isCardExpired(new \DateTime('2018-02-01 00:00:00')));
    }

    public function testIsCardExpiredPostApr18()
    {
        $judoPaymentMethod = new JudoPaymentMethod();
        $judoPaymentMethod->addCardToken('a', "{\"cardLastfour\":\"7954\",\"endDate\":\"0518\",\"cardType\":11}");
        $this->assertEquals(
            new \DateTime('2018-06-01 00:00:00', SoSure::getSoSureTimezone()),
            $judoPaymentMethod->getCardEndDateAsDate()
        );
        $this->assertFalse($judoPaymentMethod->isCardExpired(new \DateTime('2018-05-30 00:00:00')));
        $this->assertTrue($judoPaymentMethod->isCardExpired(new \DateTime('2018-06-01 00:00:00')));
    }
}
