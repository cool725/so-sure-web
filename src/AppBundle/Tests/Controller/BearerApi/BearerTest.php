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
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use \AppBundle\Document\DateTrait;

    public function tearDown()
    {
    }

    public function setUp()
    {
        parent::setUp();
        $this->clearRateLimit();
        $this->logout();
        self::$client->followRedirects(false);
    }

    public function testNotAuthenticatedPing()
    {
        /** @var \Symfony\Bundle\FrameworkBundle\Client $client */
        #$client = static::createClient();

        self::$client->request('GET', '/bearer-api/v1/ping', []);
        $this->assertTrue(self::$client->getResponse()->isRedirect('http://localhost/login'));
        // @todo expect a 403 permission denied
        //# $content = self::$client->getResponse()->getContent();
        // $this->assertContains('access_denied', $content);
    }

    public function testFailToAccessApiWithBadToken()
    {
        $server = [
            'Authorization' => 'Bearer bad-token',
        ];

        self::$client->request('GET', '/bearer-api/v1/ping', [], [], $server);

        // @todo expect a 403 permission denied
        $this->assertTrue(self::$client->getResponse()->isRedirect('http://localhost/login'));
    }

    public function testAuthenticatedPing()
    {
        list($email, $password) = $this->loginTestUser();
        $authInfo = $this->getBearerToken();

        $accessToken = $authInfo['access_token'];
        /** @var Client $oauth2Details */
        $oauth2Details = $authInfo['oauth2Details'];

        $params = [];
        $headers = [
            'HTTP_AUTHORIZATION' => 'Bearer '. $accessToken,
            'CONTENT_TYPE' => 'application/json',
        ];
        #var_dump($headers);die;
        self::$client->request('GET', '/bearer-api/v1/ping', $params, [], $headers);

        $content = self::$client->getResponse()->getContent();
        $this->assertContains('pong', $content);
        $this->assertContains('contractor21', $content);
    }

    private function loginTestUser(): array
    {
        $email = static::generateEmail('BearerTest'.time().random_int(1,999), $this);
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

        #$policy->setStatus(PhonePolicy::STATUS_PENDING);
        #static::$policyService->create($policy, new \DateTime('2016-10-01'));
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        static::$dm->persist($policy);
        static::$dm->flush();

        $this->login($email, $password, 'user/');

        return [$email, $password];
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
        // Generate an initial auth-token, using the client-id/secret fixture data
        self::$client->followRedirects(true);

        $state = (string)time(true);
        $params = [
            #'client_id' => '5b51ec6b636239778924b671_36v22l3ei3wgw0k4wos48kokk0cwsgo0ocggggoc84w0cw8844',
            'client_id' => LoadOauth2Data::KNOWN_CLIENT_ID,
            'scope' => 'read',
            'state' => $state,
            'response_type' => 'code',
            'redirect_uri' => LoadOauth2Data::KNOWN_CLIENT_CALLBACK_URL,
        ];
        echo "\n\n", http_build_query($params), "\n\n";#die;

        $dm = static::$container->get('doctrine_mongodb.odm.default_document_manager');
//        $x = $dm->createQueryBuilder(Client::class)
//            ->field('id')->equals('5b51ec6b636239778924b671')
//            ->getQuery()
//            ->getSingleResult();
//            #->debug()
//        ;
//var_dump([$x]);die;
        $repo = $dm->getRepository(Client::class);
        $x = $repo->findBy([
            "id" => new \MongoId("5b51ec6b636239778924b671"),   #)
            "randomId" => "36v22l3ei3wgw0k4wos48kokk0cwsgo0ocggggoc84w0cw8844"
        ]);
var_dump([$x]);die;

        $crawler = self::$client->request('GET', '/oauth/v2/auth', $params);
#echo self::$client->getResponse()->getContent();die;
        $this->assertFalse(self::$client->getResponse()->isNotFound(), 'Expected to find the client_id!');
        $this->assertNotContains('Client not found.', self::$client->getResponse()->getContent());

        self::$client->followRedirects(false);
        $form = $crawler->selectButton('Allow')->form();
        self::$client->submit($form);

        $redirectLocation = self::$client->getResponse()->headers->get('location') ?? null;
        $this->assertNotEmpty($redirectLocation);
        $this->assertContains('http://dev.so-sure.net:40080/test', $redirectLocation);
        $queryString = parse_url($redirectLocation, PHP_URL_QUERY);
        parse_str($queryString, $output);

        $this->assertArrayHasKey('state', $output);
        $this->assertArrayHasKey('code', $output);
        $this->assertSame($state, $output['state']);

        return [
            'code' => $output['code'],
            'oauth2Details' => $oauth2Details,
            #'client' => $client,
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
        $oauthClient = new \FOS\OAuthServerBundle\Model\Client();
        $token = new AccessToken();
        $token->setClient($oauthClient);
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
