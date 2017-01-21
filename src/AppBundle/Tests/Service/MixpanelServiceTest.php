<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Service\PushService;

/**
 * @group functional-nonet
 */
class MixpanelServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $mixpanel;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$mixpanel = self::$container->get('app.mixpanel');
    }

    public function tearDown()
    {
    }

    public function testBlankUserAgent()
    {
        $this->assertTrue(self::$mixpanel->isUserAgentAllowed(''));
    }

    public function testDisallowedUserAgent()
    {
        // @codingStandardsIgnoreStart
        $agents = [
            'Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.4 (KHTML, like Gecko) Chrome/98 Safari/537.4 (StatusCake)',
        ];
        // @codingStandardsIgnoreEnd
        
        foreach ($agents as $agent) {
            $this->assertFalse(self::$mixpanel->isUserAgentAllowed($agent));
        }
    }
}
