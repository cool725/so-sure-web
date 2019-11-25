<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Service\StatsService;

/**
 * @group functional-nonet
 * @group fixed
 */
class StatsServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $stats;
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
         self::$stats = self::$container->get('app.stats');
         self::$redis = self::$container->get('snc_redis.default');
    }

    public function tearDown()
    {
    }

    public function testQuote()
    {
        self::$stats->quote(1, new \DateTime('2016-01-01'), 'device', 32, true, false);
        $this->assertEquals(1, self::$redis->get('stats:cognito:1'), 'Record cog id');
        $this->assertEquals(1, self::$redis->get('stats:2016-01-01:query:device'), 'Inc device');
        $this->assertEquals(1, self::$redis->get('stats:2016-01-01:query:device:32'), 'Inc device mem');
        $this->assertFalse(self::$redis->exists('stats:rooted:device') == 1, 'No rooted');

        self::$stats->quote(1, new \DateTime('2016-01-01'), 'device', 32, true, false);
        $this->assertEquals(2, self::$redis->get('stats:cognito:1'), 'Inc cog id');
        $this->assertEquals(1, self::$redis->get('stats:2016-01-01:query:device'), 'No Inc device');
        $this->assertEquals(1, self::$redis->get('stats:2016-01-01:query:device:32'), 'No Inc device mem');
        
        self::$stats->quote(2, new \DateTime('2016-01-01'), 'device', 32, true, false);
        $this->assertEquals(2, self::$redis->get('stats:2016-01-01:query:device'), 'Inc device new cog');
        $this->assertEquals(2, self::$redis->get('stats:2016-01-01:query:device:32'), 'Inc device mem new cog');

        self::$stats->quote(3, new \DateTime('2016-01-01'), 'device2', 32, false, true);
        $this->assertEquals(1, self::$redis->get('stats:2016-01-01:missing:device2'), 'Inc missing device');
        $this->assertEquals(1, self::$redis->get('stats:2016-01-01:missing:device2:32'), 'Inc missing device mem');
        $this->assertEquals(1, self::$redis->get('stats:rooted:device2'), 'Rooted set');
    }
}
