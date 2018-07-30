<?php
namespace AppBundle\Tests\Controller\BearerApi;

use AppBundle\DataFixtures\MongoDB\d\Oauth2\LoadOauth2Data;
use AppBundle\Document\Oauth\AccessToken;
use AppBundle\Document\PhonePolicy;
use AppBundle\Oauth2Scopes;
use AppBundle\Tests\Controller\BaseControllerTest;
use Symfony\Component\BrowserKit\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test when a user tries to go to a protected URL, not logged in, bad-token, or when logged in
 *
 * @group functional-nonet
 */
class BearerTest extends BaseControllerTest
{
    use \AppBundle\Tests\UserClassTrait;

    public function setUp()
    {
        parent::setUp();
        $this->clearRateLimit();
        $this->logout();
        self::$client->followRedirects(false);
    }

    public function testNotAuthenticatedPing()
    {
        self::$client->request('GET', '/bearer-api/v1/ping', []);

        $this->assertSame(
            self::$client->getInternalResponse()->getStatus(),
            Response::HTTP_UNAUTHORIZED,
            'Going to bearer-api without token should be 401'
        );
        $this->assertContains('access_denied', self::$client->getInternalResponse()->getContent());
    }

    public function testFailToAccessApiWithBadToken()
    {
        $server = [
            'Authorization' => 'Bearer bad-token',
        ];

        self::$client->request('GET', '/bearer-api/v1/ping', [], [], $server);

        $this->assertSame(
            self::$client->getInternalResponse()->getStatus(),
            Response::HTTP_UNAUTHORIZED,
            'Going to bearer-api without token should be 401'
        );
    }

    public function testAuthenticatedPing()
    {
        // get a pre-prepared acces token
        $accessToken = $this->getAccessTokenObject('test-with-api-alister');

        $params = [];
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer '. $accessToken->getToken(),
            'CONTENT_TYPE' => 'application/json',
        ];
        self::$client->request('GET', '/bearer-api/v1/ping', $params, [], $headers);

        $content = self::$client->getResponse()->getContent();

        $expected = '{"response":"pong","data":"alister@so-sure.com"}';
        $this->assertContains('pong', $content);
        $this->assertJsonStringEqualsJsonString($expected, $content);
    }

    public function getAccessTokenObject(string $knownAccessToken): AccessToken
    {
        // we can't easily know without a search what the ID is
        $repo = self::$container->get('fos_oauth_server.access_token_manager.default');

        /** @var AccessToken $oauth2Details */
        return $repo->findTokenByToken($knownAccessToken);
    }


    /**
     * Make a new - valid - user (with time/random email) and log in
     */
    private function loginTestUser(): array
    {
        $email = static::generateEmail('BearerTest'.time().random_int(1, 999), $this);
        $password = 'bar';

        $user = static::createUser(static::$userManager, $email, $password, static::$dm);

        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2018-01-01'),
            true,
            true,
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        static::$dm->persist($policy);
        static::$dm->flush();

        $this->login($email, $password, 'user/');

        return [$email, $password];
    }

    /**
     * A valid User should already be logged in
     *
     * return a bearer token for the logged in user
     */
    private function getBearerToken(): string
    {
        $code = $this->getAuthToken(Oauth2Scopes::USER_STARLING_SUMMARY);
        return $this->getBearerTokenWithAuthToken($code);
    }

    /**
     * User presses a button to authorise sharing their data
     */
    protected function getAuthToken(string $scope): string
    {
        /** @var Client $client */
        $client = self::$client;

        // Generate an initial auth-token, using the client-id/secret fixture data
        self::$client->followRedirects(true);

        $state = (string) time();
        $params = [
            'client_id' => LoadOauth2Data::KNOWN_CLIENT_ID,
            'scope' => $scope,
            'state' => $state,
            'response_type' => 'code',
            'redirect_uri' => LoadOauth2Data::KNOWN_CLIENT_CALLBACK_URL,
        ];

        $crawler = self::$client->request('GET', '/oauth/v2/auth', $params);
        $this->assertFalse(self::$client->getResponse()->isNotFound(), 'Expected to find the client_id!');
        $this->assertNotContains(
            'Client not found.',
            self::$client->getResponse()->getContent(),
            'Expected to find the client_id!'
        );

        $this->assertContains(
            'http://localhost/starling-bank',
            self::$client->getRequest()->getUri(),
            'Expected to be on the starling-bank login page'
        );
        // @todo check for params in the session, as they will be used later, after purchase

        return $this->shortcutMakeUserToReturnAuthCode($params);
    }

    /**
     * Given an auth-code, make it into a Bearer token for use.
     */
    private function getBearerTokenWithAuthToken(string $authCode): string
    {
        $state = (string) time();
        $params = [
            'client_id' => LoadOauth2Data::KNOWN_CLIENT_ID,
            'client_secret' =>LoadOauth2Data::KNOWN_CLIENT_SECRET,
            'response_type' => 'code',

            'redirect_uri' => LoadOauth2Data::KNOWN_CLIENT_CALLBACK_URL,
            'state' => $state,

            'grant_type' => 'authorization_code',
            'code' => $authCode,
        ];

        self::$client->request('GET', '/oauth/v2/token', $params);
        $content = self::$client->getResponse()->getContent();

        $ret = json_decode($content, true);
        $this->assertArrayHasKey('access_token', $ret);
        $this->assertArrayHasKey('expires_in', $ret);
        $this->assertArrayHasKey('token_type', $ret);
        $this->assertArrayHasKey('scope', $ret);
        $this->assertArrayHasKey('refresh_token', $ret);

        $this->assertSame(
            15768000,
            $ret['expires_in'],
            'expected expires_in (seconds) to be ~6 months' // 182.5days
        );

        $this->assertInternalType('string', $ret['access_token']);
        $this->assertGreaterThanOrEqual(10, mb_strlen($ret['access_token']), 'expected a longer access_token');

        return $ret['access_token'];
    }

    /**
     * we shortcut the user flow (as if we manually [Allow]'d) & go to the final redirect_url for auth code
     */
    private function shortcutMakeUserToReturnAuthCode(array $params)
    {
        // Skipping the [Allow] form, we go right to the redirect it would send us to

        self::$client->followRedirects(true);
        $crawler = self::$client->request('GET', '/oauth/v2/auth', $params);

        // we should be redirected to /starling-bank

        $redirectLocation = self::$client->getRequest()->getUri();
        $this->assertNotNull($redirectLocation);
        $this->assertContains('/starling-bank', $redirectLocation);

        $queryString = parse_url($redirectLocation, PHP_URL_QUERY);
        parse_str($queryString, $output);

        // we should still have the client, and state in the URL
        $this->assertArrayHasKey('client_id', $output);
        $this->assertArrayHasKey('state', $output);
        $this->assertArrayHasKey('scope', $output);

        return $output['code'];
    }
}
