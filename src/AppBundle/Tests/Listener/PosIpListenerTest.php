<?php

namespace AppBundle\Tests\Listener;

use AppBundle\Tests\Controller\BaseControllerTest;

/**
 * @group functional-net
 * AppBundle\\Tests\\Listener\\PosIpListenerTest
 */
class PosIpListenerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    public function testOnKernelRequestAbstain()
    {
        $client = self::createClient();

        $crawler = self::$client->request('GET', '/');
        self::verifyResponse(200);
    }

    public function testOnKernelRequestDenied()
    {
        $client = self::createClient();

        $crawler = self::$client->request('GET', '/', [], [], [
            'REMOTE_ADDR' => '10.0.2.2'
        ]);
        self::verifyResponse(403);
    }

    public function testOnKernelRequestHelloz()
    {
        $client = self::createClient();

        $crawler = self::$client->request('GET', '/pos/helloz', [], [], [
            'REMOTE_ADDR' => '10.0.2.2'
        ]);
        self::verifyResponse(200);
    }
}
