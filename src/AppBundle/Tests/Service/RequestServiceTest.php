<?php

namespace AppBundle\Tests\Service;

use AppBundle\Service\RequestService;
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
    /** @var RequestService */
    protected static $requestService;
    protected static $client;

    public static function setUpBeforeClass()
    {
        self::$client = self::createClient();

         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

        /** @var RequestService */
         $requestService = self::$container->get('app.request');
        self::$requestService = $requestService;
    }

    public function tearDown()
    {
    }

    public function testGetDeviceOS()
    {
        // @codingStandardsIgnoreStart
        $this->assertEquals(
            'iOS',
            self::$requestService->getDeviceOS('Mozilla/5.0 (iPhone; CPU iPhone OS 10_2_1 like Mac OS X) AppleWebKit/602.4.6 (KHTML, like Gecko) Version/10.0 Mobile/14D27 Safari/602.1')
        );

        $this->assertEquals(
            'Android',
            self::$requestService->getDeviceOS('Mozilla/5.0 (Linux; Android 7.0; SAMSUNG SM-G950F Build/NRD90M) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/5.2 Chrome/51.0.2704.106 Mobile Safari/537.36')
        );

        $this->assertEquals(
            'Mac OS X',
            self::$requestService->getDeviceOS('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_7_4) AppleWebKit/534.57.2 (KHTML,  like Gecko) Version/5.1.7 Safari/534.57.2')
        );

        $this->assertEquals(
            'Windows',
            self::$requestService->getDeviceOS('Mozilla/5.0 (Windows; U; Windows NT 6.1; sv-SE) AppleWebKit/533.19.4 (KHTML, like Gecko) Version/5.0.3 Safari/533.19.4')
        );
        // @codingStandardsIgnoreEnd
    }

    public function testIsExcludedAnalyticsUserAgentBlank()
    {
        $this->assertFalse(self::$requestService->isExcludedAnalyticsUserAgent(''));
    }

    public function testIsExcludedAnalyticsIp()
    {
        $sosureIps = self::$container->getParameter('sosure_ips');
        $this->assertGreaterThan(0, count($sosureIps));

        foreach ($sosureIps as $sosureIp) {
            $this->assertTrue(self::$requestService->isExcludedAnalyticsIp($sosureIp));
        }
    }

    public function testIsExcludedPreviewPrefetch()
    {
        $crawler =  static::$client->request(
            "GET",
            "/ops/preview-prefetch"
        );
        $data = $this->verifyResponse(200);
        $this->assertFalse($data['excluded']);

        $crawler =  static::$client->request(
            "GET",
            "/ops/preview-prefetch",
            array(),
            array(),
            array(
                "HTTP_X-PURPOSE" => "preview",
                "HTTP_X-FOO" => "bar"
            ),
            []
        );
        $data = $this->verifyResponse(200);
        $this->assertTrue($data['excluded']);
        $this->assertContains('preview', $data['headers']);
        $this->assertContains('bar', $data['headers']);

        $crawler =  static::$client->request(
            "GET",
            "/ops/preview-prefetch",
            array(),
            array(),
            array(
                "HTTP_X-MOZ" => "prefetch"
            ),
            []
        );
        $data = $this->verifyResponse(200);
        $this->assertTrue($data['excluded']);
        $this->assertContains('prefetch', $data['headers']);
    }

    protected function verifyResponse($statusCode)
    {
        $this->assertEquals($statusCode, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);

        return $data;
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
            'Go-http-client/1.1',
            'Mozilla/5.0 (compatible; Google-Apps-Script)',
            'HappyApps-WebCheck/1.0',
            'Mozilla/5.0 (compatible; YandexVideoParser/1.0; +http://yandex.com/bots)',
            'Branch Metrics API',
            //'Mozilla/5.0 (Linux; Android 8.0.0; SM-G950F Build/R16NW; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/66.0.3359.158 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/172.0.0.66.93;]' // facebook
        ];
        // @codingStandardsIgnoreEnd
        
        foreach ($agents as $agent) {
            $this->assertTrue(self::$requestService->isExcludedAnalyticsUserAgent($agent), $agent);
        }
    }
}
