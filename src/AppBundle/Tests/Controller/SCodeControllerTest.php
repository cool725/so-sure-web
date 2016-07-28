<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\SCode;

/**
 * @group functional-net
 */
class SCodeControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
    }

    public function testSCode()
    {
        $repo = self::$dm->getRepository(SCode::class);
        $scode = $repo->findOneBy(['active' => true]);
        $this->assertNotNull($scode);
        $url = sprintf('/scode/%s', $scode->getCode());
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
    }
}
