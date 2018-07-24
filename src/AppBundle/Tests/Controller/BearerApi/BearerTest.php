<?php
namespace AppBundle\Tests\Controller\BearerApi;

use AppBundle\Controller\BearerApi\Bearer;
use AppBundle\DataFixtures\MongoDB\b\User\LoadUserData as LoadUserDataForPassword;
use AppBundle\DataFixtures\MongoDB\d\Oauth2\LoadOauth2Data;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Oauth\Client;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Tests\Controller\BaseControllerTest;
use FOS\OAuthServerBundle\Document\AccessToken;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorage;

/**-
 * @group functional-nonet
 * @group functional-net
 */
class BearerTest extends BaseControllerTest
{
    use \AppBundle\Tests\UserClassTrait;
    use \AppBundle\Document\DateTrait;

    public function testNotAuthenticatedPing()
    {;
        /** @var \Symfony\Bundle\FrameworkBundle\Client $client */
        $client = static::createClient();
        $client->followRedirects(true);

        $params = [];
        self::$client->request('GET', '/bearer-api/v1/proof', $params);

        $content = self::$client->getResponse()->getContent();
        $this->assertTrue(self::$client->getResponse()->isRedirect('http://localhost/login'));
        // @todo expect a 403 permission denied
        // $this->assertContains('access_denied', $content);
    }

    public function testFailToAccessApiWithBadToken()
    {
        /** @var \Symfony\Bundle\FrameworkBundle\Client $client */
        #self::$client = static::createClient();
        self::$client->followRedirects(false);

        $server = [
            'Authorization' => 'Bearer bad-token',
        ];

        self::$client->request('GET', '/bearer-api/v1/proof', [], [], $server);
        // @todo expect a 403 permission denied
        $this->assertTrue(self::$client->getResponse()->isRedirect('http://localhost/login'));
    }

    public function testMakeUser()
    {
        $this->markTestIncomplete(__METHOD__);
        $this->createTestUser();
        self::$client->request('GET', '/user', []);

        $content = self::$client->getResponse();
        #$this->assertSame($content);
        var_dump($content);
    }

    public function testMakeBearerToken(): string
    {
        $authInfo = $this->getBearerToken();
    }

    public function testProof()
    {
        $this->markTestIncomplete('Not yet making a new bearer-token in a test');
        $this->createTestUser();
        $bearer = $this->getBearerToken();

        self::$client->followRedirects(true);

        $server = [
            'Authorization' => 'Bearer ' . $bearer,
        ];
        self::$client->request('GET', '/bearer-api/v1/proof', [], [], $server);

        $this->assertEquals('http://localhost/bearer-api/v1/proof', self::$client->getRequest()->getUri());
        var_dump(self::$client->getResponse()->headers);
        var_dump(self::$client->getResponse()->getContent());
    }

    private function createTestUser()
    {
        $user = static::createUser(
            static::$userManager,
            $email = static::generateEmail('BearerTest'.time(), $this),
            $password = 'bar',
            static::$dm
        );
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            new \DateTime('2018-01-01'),
            true,
            true,
            true
        );
        #$policy->setStatus(PhonePolicy::STATUS_PENDING);
        #static::$policyService->create($policy, new \DateTime('2016-10-01'));
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        static::$dm->persist($policy);
        static::$dm->flush();

        $this->login($email, $password, 'user/');
    }

    /**
     * A valid User should already be logged in
     */
    private function getBearerToken(): array
    {
        $authInfo = $this->getAuthToken();
        echo __METHOD__,':',__LINE__;die;
        $authInfo = $this->getBearerTokenWithAuthToken($authInfo);

        return $authInfo;
    }

    protected function getAuthToken()
    {
        /** @var \Symfony\Bundle\FrameworkBundle\Client */
        $client = static::createClient();
        $client->followRedirects(true);

        // Generate an initial auth-token

        // we can't easily know without a search what the ID is
        $repo = self::$dm->getRepository(Client::class);

        /** @var Client $oauth2Details */
        $oauth2Details = $repo->findBy(['id' => LoadOauth2Data::KNOWN_CLIENT_ID_KEY]);
var_dump($oauth2Details);
        $oauth2Details = $repo->findAll();
var_dump([$oauth2Details, LoadOauth2Data::KNOWN_CLIENT_ID_KEY, 3]);
echo __METHOD__,':',__LINE__;die;

        $state = (string)time(true);
        $params = [
            'client_id' => $oauth2Details->getId() .'_'. $oauth2Details->getRandomId(),
            'redirect_uri' => current($oauth2Details->getRedirectUris()),
            'response_type' => 'code',
            'state' => $state,
            'scope' => 'api',
        ];

        echo __METHOD__,':',__LINE__;die;
        $client->request('GET', self::URL .'/oauth/v2/auth', $params);
        $content = $client->getResponse()->getContent();
        $this->assertContains('This user does not have access to this section.', $content);
        $this->login($client, 'contractor21', 'password');

        $client->followRedirects(false);
        $crawler = $client->request('GET', self::URL .'/oauth/v2/auth', $params);
        $form = $crawler->selectButton('Allow')->form();
        $client->submit($form);

        $redirectLocation = $client->getResponse()->headers->get('location') ?? null;
        $this->assertNotEmpty($redirectLocation);
        $this->assertContains(self::URL .'/test', $redirectLocation);
        $queryString = parse_url($redirectLocation, PHP_URL_QUERY);
        parse_str($queryString, $output);

        $this->assertArrayHasKey('state', $output);
        $this->assertArrayHasKey('code', $output);
        $this->assertSame($state, $output['state']);

        return [
            'code' => $output['code'],
            'oauth2Details' => $oauth2Details,
            'client' => $client,
        ];
    }

    private function generateNewBearerToken($clientId, $clientSecret)
    {
        echo __METHOD__,':',__LINE__;die;
        $authToken = $this->oauth2GetAuthToken($clientId, $clientSecret);
var_dump(['authToken'=>$authToken]);

        return 'authToken:'.$authToken;
    }

    protected function createToken($tokenString, $expiresAt = false)
    {
        echo __METHOD__,':',__LINE__;die;
        self::$client = new \FOS\OAuthServerBundle\Model\Client();
        $token = new AccessToken();
        $token->setClient(self::$client);
        $token->setToken($tokenString);
        if ($expiresAt) {
            $token->setExpiresAt($expiresAt);
        }
        $token->save();

        return $token;
    }

    /**
     * @param self::$clientId
     * @param self::$clientSecret
     */
    private function oauth2GetAuthToken($clientId, $clientSecret)
    {
        echo __METHOD__,':',__LINE__;die;
        $this->login('alister@so-sure.com', LoadUserDataForPassword::DEFAULT_PASSWORD, 'admin/');

        $basicAuthHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);
        self::$client->followRedirects(true);
        $server = [
            'Authorization' => $basicAuthHeader,
            'response_type' => 'code',
            'client_id' => LoadOauth2Data::KNOWN_CLIENT_ID,
            'redirect_uri' => 'http://dev.so-sure.net:40080/',
            'scope' => 'read',
            #'state' => $session->getId(),
        ];

        // 'alister' redirects to /admin/
        $crawler = self::$client->request('GET', '/oauth/v2/auth', [], [], $server);
        var_dump([__METHOD__=>self::$client->getResponse()]);die;
    }
}
