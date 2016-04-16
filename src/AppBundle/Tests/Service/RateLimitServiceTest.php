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

    public function testRateLimits()
    {
        $rates = [
            RateLimitService::TYPE_ADDRESS,
            RateLimitService::TYPE_IMEI,
            RateLimitService::TYPE_LOGIN,
            RateLimitService::TYPE_POLICY
        ];
        foreach ($rates as $type) {
            for ($i = 1; $i < 100; $i++) {
                $allowedIp = self::$rateLimit->allowed(
                    $type,
                    '1.1.1.1',
                    sprintf('address-cog-%d', $i)
                );
                if ($i <= RateLimitService::$maxRequests[$type] * RateLimitService::IP_ADDRESS_MULTIPLIER) {
                    $this->assertTrue($allowedIp);
                } else {
                    $this->assertFalse($allowedIp);
                }

                $allowedCognito = self::$rateLimit->allowed(
                    $type,
                    sprintf('1.1.2.%d', $i),
                    'address-cog-cog'
                );
                if ($i <= RateLimitService::$maxRequests[$type]) {
                    $this->assertTrue($allowedCognito);
                } else {
                    $this->assertFalse($allowedCognito);
                }
            }
        }
    }
}
