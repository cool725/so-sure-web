<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 * AppBundle\\Tests\\Service\\RequestServiceTest
 */
class RequestServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $requestService;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$requestService = self::$container->get('app.request');
    }

    public function tearDown()
    {
    }

    public function testIsExcludedAnalyticsUserAgentBlank()
    {
        $this->assertFalse(self::$requestService->isExcludedAnalyticsUserAgent(''));
    }

    public function testIsExcludedAnalyticsUserAgentTrue()
    {
        // from bots found in mixpanel and
        // https://deviceatlas.com/blog/list-of-web-crawlers-user-agents
        // @codingStandardsIgnoreStart
        $agents = [
            'Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.4 (KHTML, like Gecko) Chrome/98 Safari/537.4 (StatusCake)',
            'SeznamBot',
            'Googlebot',
            'Sogou web spider',
            'Baiduspider',
            'AdsBot-Google',
            'AhrefsBot',
            'AppleBot',
            'Baiduspider',
            'bingbot',
            'bitlybot',
            'com/bot',
            'crawler',
            'crawler/4j',
            'Exabot',
            'FacebookBot',
            'Laserlikebot',
            'Linkbot',
            'linkdexbot',
            'LiveldapBot',
            'MJ12bot',
            'Google (+https://developers.google.com/+/web/snippet/)',
            'ia_archiver (+http://www.alexa.com/site/help/webmasters; crawler@alexa.com)',
            'ia_archiver (+http://www.alexa.com/site/help/webmasters;)',
            'SimplePie/1.3.1 (Feed Parser; http://simplepie.org; Allow like Gecko) Build/20121030175911',
            'okhttp/2.5.0',
            'curl/7.35.0',
            'Mozilla/5.0 (X11; Ubuntu; Linux i686; rv:14.0; ips-agent) Gecko/20100101 Firefox/14.0.1',
            'Mozilla/5.0 (compatible; ScoutJet; +http://www.scoutjet.com/)',
        ];
        // @codingStandardsIgnoreEnd
        
        foreach ($agents as $agent) {
            $this->assertTrue(self::$requestService->isExcludedAnalyticsUserAgent($agent), $agent);
        }
    }
}
