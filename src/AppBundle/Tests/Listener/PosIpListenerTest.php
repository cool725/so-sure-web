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

    public function testOnKernelRequestNonPosPathNonPosIp()
    {
        $client = self::createClient();

        $crawler = self::$client->request('GET', '/');
        self::verifyResponse(200);
    }

    public function testOnKernelRequestPosPathNonPosIp()
    {
        $client = self::createClient();

        $crawler = self::$client->request('GET', '/pos/helloz');
        self::verifyResponse(302);
    }

    public function testOnKernelRequestDenied()
    {
        $client = self::createClient();
        $this->assertNotNull($client->getContainer());

        if ($client->getContainer()) {
            $posIps = $client->getContainer()->getParameter('pos_ips');

            $crawler = self::$client->request('GET', '/', [], [], [
                'REMOTE_ADDR' => $posIps[0]
            ]);
            self::verifyResponse(403);
        }
    }

    public function testOnKernelRequestHelloz()
    {
        $client = self::createClient();
        $this->assertNotNull($client->getContainer());

        if ($client->getContainer()) {
            $posIps = $client->getContainer()->getParameter('pos_ips');

            $crawler = self::$client->request('GET', '/pos/helloz', [], [], [
                'REMOTE_ADDR' => $posIps[0]
            ]);
            self::verifyResponse(200);
        }
    }
}
