<?php
namespace App\Tests\Controller\BearerApi;

use AppBundle\Document\Oauth\AccessToken;
use AppBundle\Tests\Controller\BaseControllerTest;
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

        $this->assertContains('pong', $content);
        $this->assertJsonStringEqualsJsonString(
            '{"response":"pong","data":"alister@so-sure.com"}',
            $content
        );
    }

    /**
     * Get one of the pre-generated access tokens.
     *
     * It is the bearer-token for a specific user to go to /bearer-api/....
     */
    private function getAccessTokenObject(string $knownAccessToken): AccessToken
    {
        // we can't easily know without a search what the ID is
        $repo = self::$container->get('fos_oauth_server.access_token_manager.default');

        /** @var AccessToken $oauth2Details */
        return $repo->findTokenByToken($knownAccessToken);
    }
}
