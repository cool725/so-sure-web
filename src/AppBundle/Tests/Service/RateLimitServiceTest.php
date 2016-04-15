<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-nonet
 */
class RateLimitServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $rateLimit;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$rateLimit = self::$container->get('app.ratelimit');
    }

    public function tearDown()
    {
    }

    public function testAddressRateLimitIp()
    {
        for ($i = 1; $i < 30; $i++) {
            $allowed = self::$rateLimit->allowed(
                RateLimitService::TYPE_ADDRESS,
                '1.1.1.1',
                sprintf('address-cog-%d', $i)
            );
            if ($i <= 21) {
                $this->assertTrue($allowed);
            } else {
                $this->assertFalse($allowed);
            }
        }
    }

    public function testAddressRateLimitCognito()
    {
        for ($i = 1; $i < 10; $i++) {
            $allowed = self::$rateLimit->allowed(
                RateLimitService::TYPE_ADDRESS,
                sprintf('1.1.2.%d', $i),
                'address-cog-cog'
            );
            if ($i <= 3) {
                $this->assertTrue($allowed);
            } else {
                $this->assertFalse($allowed);
            }
        }
    }

    public function testImeiRateLimitIp()
    {
        for ($i = 1; $i < 30; $i++) {
            $allowed = self::$rateLimit->allowed(
                RateLimitService::TYPE_IMEI,
                '1.1.3.1',
                sprintf('address-imei-%d', $i)
            );
            if ($i <= 14) {
                $this->assertTrue($allowed);
            } else {
                $this->assertFalse($allowed);
            }
        }
    }

    public function testImeiRateLimitCognito()
    {
        for ($i = 1; $i < 10; $i++) {
            $allowed = self::$rateLimit->allowed(
                RateLimitService::TYPE_IMEI,
                sprintf('1.1.4.%d', $i),
                'address-imei-cog'
            );
            if ($i <= 2) {
                $this->assertTrue($allowed);
            } else {
                $this->assertFalse($allowed);
            }
        }
    }
}
