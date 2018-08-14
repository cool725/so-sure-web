<?php
namespace Tests\App\Controller\BearerApi;

use App\Oauth2Scopes;
use AppBundle\Tests\Controller\BaseControllerTest;
use Tests\Traits;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test when a user tries to go to a protected URL, not logged in, bad-token, or when logged in
 *
 * @group functional-nonet
 */
class BearerTest extends BaseControllerTest
{
    use Traits\UserCreation;
    use Traits\Oauth;

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
        $params = [];
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer test-with-api-alister',
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

    public function testAuthenticatedComplexUser()
    {
        $user = $this->generateUserWithTwoPolicies(12.50);

        $token = 'accessToken' . random_int(1, 1E7);
        $dm = self::$container->get('doctrine.odm.mongodb.document_manager');

        $mongoId = new \MongoId();
        $clientToken = $this->newOauth2Client(
            $dm,
            (string) $mongoId,
            'clientIdRandom',
            'clientSecret',
            [Oauth2Scopes::USER_STARLING_SUMMARY],
            []
        );
        $this->newOauth2AccessToken($dm, $clientToken, $user, $token);

        $params = [];
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ];
        self::$client->request('GET', '/bearer-api/v1/user', $params, [], $headers);

        $content = self::$client->getResponse()->getContent();

        $this->assertContains('Foo Bar', $content);

        $summary = json_decode($content, true);
        $this->assertSummaryMatchesUserWithTwoPolicies($summary);
    }
}
