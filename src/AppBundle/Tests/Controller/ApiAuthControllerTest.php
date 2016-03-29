<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

/**
 * @group functional-net
 */
class ApiAuthControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
    }

    // auth

    /**
     *
     */
    public function testAuthRequiresIdentity()
    {
        $client = static::createClient();
        $crawler = $client->request('POST', '/api/v1/auth/ping');
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
    }

    /**
     *
     */
    public function testGetIsAnon()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/auth/ping');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }
}
