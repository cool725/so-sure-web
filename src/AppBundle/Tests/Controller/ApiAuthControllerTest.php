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
    use \AppBundle\Tests\UserClassTrait;

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

    // user/{id}

    /**
     *
     */
    public function testGetUser()
    {
        $client = static::createClient();
        $user = static::createUser($this->getUserManager($client), 'getuser@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getAuthUser($client, $user);
        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $crawler = static::postRequest($client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('getuser@auth-api.so-sure.com', $data['email']);
    }

    public function testGetUnAuthUser()
    {
        $client = static::createClient();
        $user = static::createUser($this->getUserManager($client), 'getuser-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $crawler = static::postRequest($client, $cognitoIdentityId, $url, []);
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
    }

    protected function getAuthUser($client, $user)
    {
        return static::authUser($client->getContainer()->get('app.cognito.identity'), $user);
    }

    protected function getUnauthIdentity($client)
    {
        return static::getIdentityString($client->getContainer()->get('app.cognito.identity')->getId());
    }

    protected function getUserManager($client)
    {
        return $client->getContainer()->get('fos_user.user_manager');
    }
}
