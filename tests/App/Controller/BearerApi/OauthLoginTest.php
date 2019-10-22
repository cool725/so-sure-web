<?php

namespace Tests\App\Controller\BearerApi;

use App\Oauth2Scopes;
use AppBundle\DataFixtures\MongoDB\d\Oauth2\LoadOauth2Data;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Test when a user needs to login to get a token issued, they go to the right place
 *
 * NOTE: This is an event being run by a human, clicking links. Not an API call.
 *
 * @group functional-nonet
 */
class OauthLoginTest extends WebTestCase
{
    /** @var Client $client */
    private $client;
    /** @var ContainerInterface|null $container */
    private $container;

    public function setUp()
    {
        $this->client = static::createClient();
        if (!$this->client instanceof Client) {
            throw new AssertionFailedError('did not get a valid web-client');
        }

        $this->container = $this->client->getContainer();
        if (!$this->container instanceof ContainerInterface) {
            throw new AssertionFailedError('did not get a valid ContainerInterface');
        }
    }

    /**
     * going to the oauth/v2/auth page with bad creds redirects to /login
     */
    public function testOauthRedirectionWithNoParameters()
    {
        $params = [];
        $this->client->request('GET', '/oauth/v2/auth', $params);

        $response = $this->client->getInternalResponse();
        $this->assertNotNull($response);
        $this->assertInstanceOf(Response::class, $response);
        if ($response === null || ! $response instanceof Response) {
            $this->fail('did not get a valid response from oauth/v2/auth');
            return;
        }
        $this->assertSame('/login', $response->getHeader('location'));
    }

    /**
     * going to the oauth/v2/auth page with bad creds redirects to /login
     */
    public function testOauthRedirectionWithUnknownCredentials()
    {
        $params = [
            'client_id' => '123_456',
            'state' => md5(time()),
            'response_type' => 'code',
            'scope' => "read",
            'redirect_uri' => 'http://dev.so-sure.net:40080/',
        ];
        $this->client->request('GET', '/oauth/v2/auth', $params);

        $response = $this->client->getInternalResponse();
        $this->assertNotNull($response);
        $this->assertInstanceOf(Response::class, $response);
        if ($response === null || ! $response instanceof Response) {
            $this->fail('did not get a valid response from oauth/v2/auth');
            return;
        }
        $this->assertSame('/login', $response->getHeader('location'));
    }

    public function testStarlingBankRedirectionWithKnownCredentials()
    {
        $state = md5(random_bytes(8));
        $params = [
            'client_id' => LoadOauth2Data::KNOWN_CLIENT_ID,
            'state' => $state,
            'response_type' => 'code',
            'scope' => Oauth2Scopes::USER_STARLING_SUMMARY,
            'redirect_uri' => 'http://dev.so-sure.net:40080/',
        ];
        $this->client->request('GET', '/oauth/v2/auth?', $params);

        $response = $this->client->getInternalResponse();
        $this->assertNotNull($response);
        $this->assertInstanceOf(Response::class, $response);
        if (! $response instanceof Response) {
            $this->fail('response was null');
            return;
        }
        $redirectLocation = $response->getHeader('location');
        $this->assertContains('/starling-bank', $redirectLocation);

        // Check for params in the session, as they will be used later, after purchase
        $queryString = parse_url($redirectLocation, PHP_URL_QUERY);
        parse_str($queryString, $output);

        $this->assertArrayHasKey(
            'client_id',
            $output,
            'expected the Oauth2-Params to be carried over to Starling bank Oauth/Auth landing page'
        );
        $this->assertArrayHasKey('state', $output);
        $this->assertArrayHasKey('scope', $output);

        // following the redirect is required to get the session in place
        $this->client->followRedirect();

        // the target_path, stored in the session, will be used later to go back to Starling
        if (! $this->client->getContainer()) {
            $this->fail('testing container, over and over again..., this time, in the client');
            return;
        }
        $session = $this->client->getContainer()->get('session');
        $this->assertNotNull($session);
        $this->assertInstanceOf(SessionInterface::class, $session);
        if ($session == null || ! $session instanceof SessionInterface) {
            $this->fail('Do not have a valid session');
            return;
        }

        $this->assertTrue(
            $session->has('oauth2Flow.targetPath'),
            'expected the Oauth2-Params to be carried over, via the session'
        );
    }

    public function testStarlingBusinessRedirectionWithKnownCredentials()
    {
        $state = md5(random_bytes(8));
        $params = [
            'client_id' => LoadOauth2Data::KNOWN_CLIENT_ID,
            'state' => $state,
            'response_type' => 'code',
            'scope' => Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY,
            'redirect_uri' => 'http://dev.so-sure.net:40080/',
        ];
        $this->client->request('GET', '/oauth/v2/auth?', $params);

        $response = $this->client->getInternalResponse();
        $this->assertNotNull($response);
        $this->assertInstanceOf(Response::class, $response);
        if (! $response instanceof Response) {
            $this->fail('response was null');
            return;
        }
        $redirectLocation = $response->getHeader('location');
        $this->assertContains('/starling-business', $redirectLocation);

        // Check for params in the session, as they will be used later, after purchase
        $queryString = parse_url($redirectLocation, PHP_URL_QUERY);
        parse_str($queryString, $output);

        $this->assertArrayHasKey(
            'client_id',
            $output,
            'expected the Oauth2-Params to be carried over to Starling bank Oauth/Auth landing page'
        );
        $this->assertArrayHasKey('state', $output);
        $this->assertArrayHasKey('scope', $output);

        // following the redirect is required to get the session in place
        $this->client->followRedirect();

        // the target_path, stored in the session, will be used later to go back to Starling
        if (! $this->client->getContainer()) {
            $this->fail('testing container, over and over again..., this time, in the client');
            return;
        }
        $session = $this->client->getContainer()->get('session');
        $this->assertNotNull($session);
        $this->assertInstanceOf(SessionInterface::class, $session);
        if ($session == null || ! $session instanceof SessionInterface) {
            $this->fail('Do not have a valid session');
            return;
        }

        $this->assertTrue(
            $session->has('oauth2Flow.targetPath'),
            'expected the Oauth2-Params to be carried over, via the session'
        );
    }
}
