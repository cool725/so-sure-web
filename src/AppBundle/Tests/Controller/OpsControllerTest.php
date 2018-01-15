<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\OpsControllerTest
 */
class OpsControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;


    public function tearDown()
    {
    }

    public function testCspEmpty()
    {
        $crawler = self::$client->request(
            'POST',
            '/ops/csp',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json')
        );
        self::verifyResponse(411);
    }

    public function testCspBlank()
    {
        $crawler = self::$client->request(
            'POST',
            '/ops/csp',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(['body' => []])
        );

        self::verifyResponse(400);
    }

    public function testCspHost()
    {
        $crawler = self::$client->request(
            'POST',
            '/ops/csp',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(['csp-report' => ['blocked-uri' => 'http://www.bizographics.com']])
        );

        self::verifyResponse(204);
    }

    public function testCspIp()
    {
        $crawler = self::$client->request(
            'POST',
            '/ops/csp',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(['csp-report' => ['blocked-uri' => 'http://10.0.1.0']])
        );
        self::verifyResponse(204);
    }

    public function testCspBlob()
    {
        $crawler = self::$client->request(
            'POST',
            '/ops/csp',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(['csp-report' => ['blocked-uri' => 'blob']])
        );
        self::verifyResponse(204);
    }

    public function testCspLog()
    {
        self::$client->request(
            'POST',
            '/ops/csp',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(['csp-report' => ['blocked-uri' => 'http://www.mytesturi.com']])
        );
        self::$client->request(
            'POST',
            '/ops/csp',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(['csp-report' => ['blocked-uri' => 'http://www.mytesturi.com']])
        );
        self::$client->request(
            'POST',
            '/ops/csp',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(['csp-report' => ['blocked-uri' => 'http://www.mytesturi.com']])
        );


        $items = [];
        while (($item = self::$redis->lpop('csp')) != null) {
            $data = json_decode($item, true);
            $this->assertTrue(isset($data['csp-report']['user-agent']));
            $this->assertContains('Symfony', $data['csp-report']['user-agent']);
            $items[] = $item;
        }
        self::verifyResponse(204);
    }
}
