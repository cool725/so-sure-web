<?php
namespace App\Tests\Controller\BearerApi;

use App\Oauth2Scopes;
use App\Tests\Traits;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Tests\Controller\BaseControllerTest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test when there is an API call to go a protected URL - no-token, bad-token, or happy-path success
 *
 * @group functional-nonet
 */
class BearerTest extends BaseControllerTest
{
    use \AppBundle\Tests\UserClassTrait;
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
            self::$client->getInternalResponse() ? self::$client->getInternalResponse()->getStatus() : null,
            Response::HTTP_UNAUTHORIZED,
            'Going to bearer-api without token should be 401'
        );
        $this->assertContains(
            'access_denied',
            self::$client->getInternalResponse() ? self::$client->getInternalResponse()->getContent() : null
        );
    }

    public function testFailToAccessApiWithBadToken()
    {
        $server = [
            'Authorization' => 'Bearer bad-token',
        ];

        self::$client->request('GET', '/bearer-api/v1/ping', [], [], $server);

        $this->assertSame(
            self::$client->getInternalResponse() ? self::$client->getInternalResponse()->getStatus() : null,
            Response::HTTP_UNAUTHORIZED,
            'Going to bearer-api without token should be 401'
        );
    }

    public function testAuthenticatedPing()
    {
        $params = [];
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer test-with-api-employee',
            'CONTENT_TYPE' => 'application/json',
        ];
        self::$client->request('GET', '/bearer-api/v1/ping', $params, [], $headers);

        $content = $this->getClientResponseContent();

        $this->assertContains('pong', $content);
        $this->assertJsonStringEqualsJsonString(
            '{"response":"pong","data":"employee@so-sure.com"}',
            $content
        );
    }

    public function testAuthenticatedComplexUser()
    {
        $rewardPotValue = 12.50;
        $user = $this->generateUserWithTwoPolicies($rewardPotValue);

        $token = 'accessToken' . random_int(1, 1E7);
        $dm = self::$container->get('doctrine.odm.mongodb.document_manager');

        $mongoId = new \MongoId();
        $clientToken = $this->newOauth2Client(
            $dm,
            (string) $mongoId,
            'clientIdRandom',
            'clientSecret',
            [
                Oauth2Scopes::USER_STARLING_SUMMARY,
                Oauth2Scopes::USER_STARLING_BUSINESS_SUMMARY
            ],
            [ 'http://dev.so-sure.net:40080/' ]
        );
        $this->newOauth2AccessToken($dm, $clientToken, $user, $token);

        $params = [];
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ];
        self::$client->request('GET', '/bearer-api/v1/user', $params, [], $headers);

        $content = $this->getClientResponseContent();

        $this->assertContains('Expiry Date', $content);

        $summary = json_decode($content, true);
        $this->assertSummaryMatchesUserWithTwoPolicies($summary);
    }

    /**
     * Make a user, with a policy
     *
     * @see \AppBundle\Tests\Controller\UserControllerTest::testUserInvite();
     */
    public function generateUserWithTwoPolicies(float $rewardPotValue = 0): User
    {
        $email = self::generateEmail('testUser'.random_int(PHP_INT_MIN, PHP_INT_MAX), $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setPotValue($rewardPotValue);

        self::$dm->flush();

        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $phone2 = self::getRandomPhone(self::$dm);
        $policy = self::initPolicy($user, self::$dm, $phone2, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        self::$dm->flush();

        return $user;
    }

    protected function assertSummaryMatchesUserWithTwoPolicies(array $summary)
    {
        $this->assertNotNull($summary);
        $this->assertNotEmpty($summary);

        $this->assertArrayHasKey('widgets', $summary);
        #$this->assertArrayHasKey('policies', $summary);

        $this->assertCount(5, $summary['widgets']);
        for ($i = 0; $i < count($summary['widgets']); $i++) {
            $this->assertPolicySummaryHasKeys($summary['widgets'][$i]);
        }
    }

    private function assertPolicySummaryHasKeys(array $widget)
    {
        $this->assertArrayHasKey('type', $widget);
        $this->assertSame('TEXT', $widget['type']);
        $this->assertArrayHasKey('title', $widget);
        $this->assertArrayHasKey('text', $widget);
    }
}
