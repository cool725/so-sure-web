<?php

namespace AppBundle\Tests\Controller\BearerApi;

use AppBundle\Oauth2Scopes;
use function GuzzleHttp\Psr7\build_query;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Test when a user needs to login to get a token issued, they go to the right place
 */
class OauthLoginTest extends WebTestCase
{
    public function testOauthRedirection()
    {
        $client = static::createClient();

        $params = [
            'client_id' => '123_456',
            'state' => md5(time()),
            'response_type' => 'code',
            'scope' => "read",
            'redirect_uri' => 'http://dev.so-sure.net:40080/',
        ];
        $client->request('GET', '/oauth/v2/auth?'. build_query($params));

        $this->assertSame('/login', $client->getInternalResponse()->getHeader('location'));
    }

    public function testStarlingRedirection()
    {
        $client = static::createClient();

        $state = md5(time());
        $params = [
            'client_id' => '5b51ec6b636239778924b671_36v22l3ei3wgw0k4wos48kokk0cwsgo0ocggggoc84w0cw8844',
            'state' => $state,
            'response_type' => 'code',
            'scope' => Oauth2Scopes::USER_STARLING_SUMMARY,
            'redirect_uri' => 'http://dev.so-sure.net:40080/',
        ];
        $client->request('GET', '/oauth/v2/auth?'. build_query($params));

        $this->assertContains('/starling-bank', $client->getInternalResponse()->getHeader('location'));
        $this->assertContains(
            $state,
            $client->getInternalResponse()->getHeader('location'),
            'expected the Oauth2-Params to be carried over to the login/[Allow] page'
        );
    }
}
