<?php

namespace AppBundle\Tests\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Service\RateLimitService;
use AppBundle\Document\User;

/**
 * @group functional-nonet
 */
class RateLimitServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $rateLimit;
    protected static $redis;

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
         self::$redis = self::$container->get('snc_redis.default');
         /** @var DocumentManager */
         $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$dm = $dm;
    }

    public function tearDown()
    {
    }

    public function testDeviceRateLimits()
    {
        $rates = [
            RateLimitService::DEVICE_TYPE_ADDRESS,
            RateLimitService::DEVICE_TYPE_IMEI,
            RateLimitService::DEVICE_TYPE_LOGIN,
            RateLimitService::DEVICE_TYPE_POLICY,
            RateLimitService::DEVICE_TYPE_RESET,
            RateLimitService::DEVICE_TYPE_TOKEN,
            RateLimitService::DEVICE_TYPE_OPT,
        ];
        foreach ($rates as $type) {
            for ($i = 1; $i < 100; $i++) {
                $allowedIp = self::$rateLimit->allowedByDevice(
                    $type,
                    '1.1.1.1',
                    sprintf('address-cog-%d', $i)
                );
                if ($i <= RateLimitService::$maxIpRequests[$type]) {
                    $this->assertTrue($allowedIp);
                } else {
                    $this->assertFalse($allowedIp);
                }

                $allowedCognito = self::$rateLimit->allowedByDevice(
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

    public function testUserRateLimits()
    {
        $rates = [
            RateLimitService::DEVICE_TYPE_USER_LOGIN,
        ];
        $repo = static::$dm->getRepository(User::class);
        $user = $repo->findOneBy([]);
        $rateLimited = false;

        foreach ($rates as $type) {
            for ($i = 1; $i < 100; $i++) {
                $userLimited = self::$rateLimit->allowedByUser($user);
                if ($i <= RateLimitService::$maxRequests[$type]) {
                    $this->assertTrue($userLimited);
                    $rateLimited = true;
                } else {
                    $this->assertFalse($userLimited);
                }
            }
        }
        $this->assertTrue($rateLimited);
    }

    /*
    public function testUserKeyRateLimits()
    {
        $user = new User();
        $user->setId(1);
        $rates = [
            RateLimitService::KEY_TYPE_DAILY_EMAIL_INVITATION,
            RateLimitService::KEY_TYPE_DAILY_SMS_INVITATION,
        ];
        foreach ($rates as $type) {
            for ($i = 1; $i < 10; $i++) {
                $allowed = self::$rateLimit->allowedByUser(
                    $user,
                    $type,
                    'foo',
                    false
                );
                if ($i <= RateLimitService::$maxRequests[$type]) {
                    $this->assertTrue($allowed);
                } else {
                    $this->assertFalse($allowed);
                }
            }
        }
    }
    public function testUserKeyRateLimitsSlidingWindow()
    {
        $user = new User();
        $user->setId(1);
        self::$rateLimit->allowedByUser(
            $user,
            RateLimitService::KEY_TYPE_DAILY_EMAIL_INVITATION,
            'bar',
            true,
            false
        );
        $expire = self::$redis->ttl(sprintf(
            RateLimitService::USER_KEY_FORMAT,
            1,
            RateLimitService::KEY_TYPE_DAILY_EMAIL_INVITATION,
            'bar'
        ));
        $this->assertGreaterThan(0, $expire);
        sleep(2);

        self::$rateLimit->allowedByUser(
            $user,
            RateLimitService::KEY_TYPE_DAILY_EMAIL_INVITATION,
            'bar',
            true,
            false
        );
        $newExpire = self::$redis->ttl(sprintf(
            RateLimitService::USER_KEY_FORMAT,
            1,
            RateLimitService::KEY_TYPE_DAILY_EMAIL_INVITATION,
            'bar'
        ));
        $this->assertTrue($expire > $newExpire);
    }
    */
}
