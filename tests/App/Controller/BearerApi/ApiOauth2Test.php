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
 * Test access and failures, with codes for automated access by another machine
 *
 * How the exceptions are gonna get changed is a different problem....
 *
 * @group functional-nonet
 */
class ApiOauth2Test extends WebTestCase
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

    // post a valid client_id, secret & code to get an access_token
    //#public function testOauthGetAccessToken()

    /**
     * going to the oauth/v2/auth page with bad creds redirects to /login
     */
    public function testOauthSwapTokenWithNoParametersReturnsStarlingErrors()
    {
        #$this->markTestIncomplete();

        $params = [];
        $this->client->request('GET', '/oauth/v2/token', $params);
        $response = $this->client->getInternalResponse();
        // what we want for Starling
        $this->assertJsonError(400, 'invalid_request', 'grant_type must be provided', $response);

        $params['grant_type'] = 'token';    // set it to something 'valid', but not useful here yet
        $this->client->request('GET', '/oauth/v2/token', $params);
        $response = $this->client->getInternalResponse();
        // what we want for Starling
        $this->assertJsonError(400, 'invalid_request', 'client_id must be specified', $response);

        $params['client_id'] = 'blah';
        $this->client->request('GET', '/oauth/v2/token', $params);
        $response = $this->client->getInternalResponse();
        $this->assertJsonError(
            400,
            'invalid_request',
            'Client not authorised to access token or authentication failed',
            $response
        );

        $params['client_secret'] = 'blah';
        $this->client->request('GET', '/oauth/v2/token', $params);
        $response = $this->client->getInternalResponse();
        $this->assertJsonError(
            400,
            'invalid_request',
            'Client not authorised to access token or authentication failed',
            $response
        );

        $params['grant_type'] = 'authorization_code';
        $params['client_id'] = LoadOauth2Data::KNOWN_CLIENT_ID;
        $params['client_secret'] = LoadOauth2Data::KNOWN_CLIENT_SECRET;
        $this->client->request('GET', '/oauth/v2/token', $params);
        $response = $this->client->getInternalResponse();
        $this->assertJsonError(400, 'invalid_request', 'authorization_code must be specified', $response);

        $params['code'] = '12331231231';
        $this->client->request('GET', '/oauth/v2/token', $params);
        $response = $this->client->getInternalResponse();
        $this->assertJsonError(
            400,
            'invalid_request',
            'redirect_uri must match the value provided when getting the authorization code',
            $response
        );

        $params['redirect_uri'] = 'http://dev.so-sure.net:40080/ops/pages';
        $this->client->request('GET', '/oauth/v2/token', $params);
        $response = $this->client->getInternalResponse();
        $this->assertJsonError(
            400,    // this error is a 403 in starling docs
            'invalid_request',
            'authorization code could not be validated. It could be invalid, expired or revoked',
            $response
        );
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
        $this->assertArrayHasKey('utm_source', $output);
        $this->assertArrayHasKey('utm_campaign', $output);
        $this->assertArrayHasKey('utm_medium', $output);

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
        $this->assertArrayHasKey('utm_source', $output);
        $this->assertArrayHasKey('utm_campaign', $output);
        $this->assertArrayHasKey('utm_medium', $output);

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

    private function assertJsonError(int $statusCode, string $errorCode, string $errorDescription, Response $response)
    {
        $this->assertSaneResponse($response);

        $this->assertEquals($response->getStatus(), $statusCode, 'status code does not match expected');
        $this->assertJson($response->getContent(), 'Expected the return to be JSON');

        $data = json_decode($response->getContent());
        $this->assertNotNull($data->{'error-code'}, "Starling-compatible ->{error-code} does not exist");
        $this->assertSame($data->{'error-code'}, $errorCode, "not the expected ->error");
        $this->assertSame($data->error_description, $errorDescription, "not the expected ->error_description");
    }

    private function assertSaneResponse($response)
    {
        $this->assertNotNull($response, 'response should not be null');
        $this->assertInstanceOf(Response::class, $response);

        if ($response === null || !$response instanceof Response) {
            $this->fail('did not get a valid response from oauth/v2/auth');
        }
    }
}
