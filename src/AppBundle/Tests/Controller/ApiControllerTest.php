<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Repository\UserRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Reward;
use AppBundle\Document\Lead;
use AppBundle\Document\SCode;
use AppBundle\Document\Sns;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-net
 */
class ApiControllerTest extends BaseApiControllerTest
{
    protected static $reward;

    public function setUp()
    {
        self::$redis->flushdb();
        self::$phone = null;
    }

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        $user = self::createUser(
            self::$userManager,
            'bonus-unauth@so-sure.net',
            'foo'
        );
        $user->setFirstName('so-sure');
        $user->setLastName('Rewards');
        self::$reward = new Reward();
        self::$reward->setUser($user);
        $scode = new SCode();
        $scode->setCode(sprintf('ssrs%d', rand(1000, 9999)));
        $scode->setReward(self::$reward);
        $scode->setType(SCode::TYPE_REWARD);
        self::$reward->setSCode($scode);
        static::$dm->persist(self::$reward);
        static::$dm->persist($scode);

        static::$dm->flush();
    }

    // login

    /**
     *
     */
    public function testLoginNoUser()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => 'foo',
            'password' => 'bar'
        ]));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_ABSENT);
    }

    public function testLoginMissingPasswordParam()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => 'foo'
        ]));
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testLoginMissingUserParam()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'password' => 'bar'
        ]));
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }


    public function testLoginBadPassword()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, 'badfoo@api.bar.com', 'bar');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => 'badfoo@api.bar.com',
            'password' => 'barfoo'
        ]));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_EXISTS);
    }

    public function testLoginOk()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, 'foo@api.bar.com', 'bar');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => 'foo@api.bar.com',
            'password' => 'bar'
        ]));
        $data = $this->verifyResponse(200);
        $this->assertEquals('foo@api.bar.com', $data['email']);
        $this->assertTrue(mb_strlen($data['cognito_token']['id']) > 10);
        $this->assertTrue(mb_strlen($data['cognito_token']['token']) > 10);
        $this->assertTrue(mb_strlen($data['intercom_token']['android_hash']) > 10, json_encode($data));
        $this->assertTrue(mb_strlen($data['intercom_token']['ios_hash']) > 10);

        /** @var DocumentManager $dm */
        $dm = $this->getDocumentManager(true);
        /** @var UserRepository $repo */
        $repo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => 'foo@api.bar.com']);
        $this->assertNotNull($user);
        $firstLogin = $user->getFirstLoginInApp();
        $this->assertNotNull($firstLogin);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => 'foo@api.bar.com',
            'password' => 'bar'
        ]));
        $data = $this->verifyResponse(200);

        $repo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => 'foo@api.bar.com']);
        $this->assertNotNull($user);
        $this->assertEquals($firstLogin, $user->getFirstLoginInApp());
    }

    public function testRateLimitLoginLocksUser()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('rate-limit-login', $this), 'bar');
        for ($i = 0; $i < 25; $i++) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
                'email' => static::generateEmail('rate-limit-login', $this),
                'password' => 'notbar'
            ]));
        }
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_SUSPENDED);

        // For some reason, querying with the same client/dm is not updating getting the latest record
        static::$client = static::createClient();
        /** @var DocumentManager $dm */
        $dm = $this->getDocumentManager(true);
        /** @var UserRepository $repo */
        $repo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy(['emailCanonical' => mb_strtolower(static::generateEmail('rate-limit-login', $this))]);
        $this->assertTrue($user->isLocked());
    }

    public function testSuccessLoginDoesNotLocksUser()
    {
        $rateLimit = new RateLimitService(
            static::$redis,
            static::$container->get('logger'),
            'test',
            static::$container->get('app.feature'),
            static::$container->getParameter('sosure_ips')
        );

        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('rate-limit-login-ok', $this), 'bar');
        for ($i = 0; $i < 11; $i++) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
                'email' => static::generateEmail('rate-limit-login-ok', $this),
                'password' => 'bar'
            ]));

            $rateLimit->clearByDevice(
                RateLimitService::DEVICE_TYPE_LOGIN,
                '127.0.0.1',
                $cognitoIdentityId
            );
        }
        $data = $this->verifyResponse(200);

        // For some reason, querying with the same client/dm is not updating getting the latest record
        self::$client = static::createClient();
        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy([
            'emailCanonical' => mb_strtolower(static::generateEmail('rate-limit-login-ok', $this))
        ]);
        $this->assertFalse($user->isLocked());
    }

    public function testLoginNoUserType()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', []);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testLoginFacebookMissingParam()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(
            self::$userManager,
            static::generateEmail('facebook-missing', $this),
            'bar'
        );

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('facebook_user' => [
            'facebook_id' => 'foo',
        ]));
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('facebook_user' => [
            'facebook_access_token' => 'foo',
        ]));
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testLoginFacebookInvalidId()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(
            self::$userManager,
            static::generateEmail('facebook-invalid-id', $this),
            'bar'
        );
        $user->setFacebookId(rand(1, 999999));
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('facebook_user' => [
            'facebook_id' => '1',
            'facebook_access_token' => 'foo',
        ]));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_ABSENT);
    }

    public function testLoginFacebookInvalidToken()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(
            self::$userManager,
            static::generateEmail('facebook-invalid-token', $this),
            'bar'
        );
        $user->setFacebookId(rand(1, 999999));
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('facebook_user' => [
            'facebook_id' => $user->getFacebookId(),
            'facebook_access_token' => 'foo',
        ]));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_EXISTS);
    }

    public function testLoginGoogleMissingParam()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(
            self::$userManager,
            static::generateEmail('google-missing', $this),
            'bar'
        );

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('google_user' => [
            'google_id' => 'foo',
        ]));
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('google_user' => [
            'google_access_token' => 'foo',
        ]));
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testLoginGoogleInvalidId()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(
            self::$userManager,
            static::generateEmail('google-invalid-id', $this),
            'bar'
        );
        $user->setGoogleId(rand(1, 999999));
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('google_user' => [
            'google_id' => '1',
            'google_access_token' => 'foo',
        ]));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_ABSENT);
    }

    public function testLoginGoogleInvalidToken()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(
            self::$userManager,
            static::generateEmail('google-invalid-token', $this),
            'bar'
        );
        $user->setGoogleId(rand(1, 999999));
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('google_user' => [
            'google_id' => $user->getGoogleId(),
            'google_access_token' => 'foo',
        ]));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_EXISTS);
    }

    public function testLoginOkUserDisabled()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('login2', $this), 'bar');

        $user->setEnabled(false);
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => static::generateEmail('login2', $this),
            'password' => 'bar'
        ]));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_RESET_PASSWORD);
    }

    public function testLoginOkUserLocked()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('login3', $this), 'bar');

        $user->setLocked(true);
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => static::generateEmail('login3', $this),
            'password' => 'bar'
        ]));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_SUSPENDED);
    }

    public function testLoginRateLimited()
    {
        $userA = static::createUser(self::$userManager, static::generateEmail('invalid-userA', $this), 'bar');
        $userB = static::createUser(self::$userManager, static::generateEmail('invalid-userB', $this), 'bar');
        $cognitoIdentityId = $this->getUnauthIdentity();

        for ($i = 1; $i <= ceil(RateLimitService::$maxRequests[RateLimitService::DEVICE_TYPE_LOGIN] / 2) + 1; $i++) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
                'email' => static::generateEmail('invalid-userA', $this),
                'password' => 'invalid'
            ]));
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
                'email' => static::generateEmail('invalid-userB', $this),
                'password' => 'invalid'
            ]));
        }
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_TOO_MANY_REQUESTS);
    }

    public function testLoginException()
    {
        $crawler = self::$client->request(
            'POST',
            '/api/v1/login?debug=true',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('username' => 'foo', 'password' => 'bar')))
        );
        $data = $this->verifyResponse(500);
    }

    public function testLoginOAuthDigigtsNotSupported()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('oauth_echo_user' => [
            'provider' => 'foo',
            'credentials' => 'bar',
        ]));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_UPGRADE_APP);
    }

   // ping

    /**
     *
     */
    public function testPing()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = self::$client->request('GET', '/api/v1/ping');
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);
    }

    // policy/terms

    /**
     *
     */
    public function testGetPolicyTerms()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = '/api/v1/policy/terms?maxPotValue=62.8&_method=GET';

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);
        $policyTermsUrl = self::$router->generate('latest_policy_terms');
        $this->assertTrue(mb_stripos($getData["view_url"], $policyTermsUrl) >= 0);
        $this->assertTrue(mb_stripos($getData["view_url"], 'http') >= 0);
        $this->assertTrue(mb_stripos($getData["view_url"], 'Version') >= 0);
    }

    public function testGetPolicyTermsValidation()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = '/api/v1/policy/terms?maxPotValue[$ne]=1&_method=GET';

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    /**
     *
     */
    public function testGetPolicyTerms2()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = '/api/v1/policy/v2/terms?maxPotValue=62.8&_method=GET';

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);
        $policyTermsUrl = self::$router->generate('latest_policy_terms2');
        $this->assertTrue(mb_stripos($getData["view_url"], $policyTermsUrl) >= 0);
        $this->assertTrue(mb_stripos($getData["view_url"], 'http') >= 0);
        $this->assertTrue(mb_stripos($getData["view_url"], 'Version') >= 0);
    }

    public function testGetPolicyTerms2Validation()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = '/api/v1/policy/v2/terms?maxPotValue[$ne]=1&_method=GET';

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    // quote

    /**
     *
     */
    public function testQuoteUnknown()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = self::$client->request('GET', '/api/v1/quote');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_QUOTE_PHONE_UNKNOWN);
    }

    public function testQuoteValidation()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = self::$client->request('GET', '/api/v1/quote?device=A0001&make[$ne]=1');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }


    public function testQuoteA0001()
    {
        $start = \DateTime::createFromFormat('U', time());
        $start->add(new \DateInterval('P1D'));

        $crawler = self::$client->request('GET', '/api/v1/quote?device=A0001');

        $end = \DateTime::createFromFormat('U', time());
        $end->add(new \DateInterval('P1D'));

        $data = $this->verifyResponse(200);

        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(2, count($data['quotes']));

        $validTo = new \DateTime($data['quotes'][0]['valid_to']);
        $this->assertGreaterThanOrEqual($start, $validTo);
        $this->assertLessThanOrEqual($end, $validTo);
        $this->assertEquals(0, $data['quotes'][0]['monthly_loss']);
        $this->assertEquals(0, $data['quotes'][0]['yearly_loss']);

        // gwp 5.93 was 6.49 (9.5% ipt), now 6.52 (10% ipt), now 6.64 (12%)
        $this->assertEquals(6.64, $data['quotes'][0]['monthly_premium']);
        $this->assertEquals(79.68, $data['quotes'][0]['yearly_premium']);
        /*
        $this->assertEquals(6.49, $data['quotes'][0]['monthly_premium']);
        $this->assertEquals(77.88, $data['quotes'][0]['yearly_premium']);
        */

        /*
         * no longer using promo codes for the quote
        $connectionValue = 15;
        $maxConnections = 6;
        $maxPot = 77.88;
        $this->assertEquals($connectionValue, $data['quotes'][0]['connection_value']);
        $this->assertEquals($maxConnections, $data['quotes'][0]['max_connections']);
        $this->assertEquals($maxPot, $data['quotes'][0]['max_pot']);

        // And verify non-promo code values
        $crawler = self::$client->request('GET', '/api/v1/quote?device=A0001&debug=true');
        $data = $this->verifyResponse(200);
        */
        $connectionValue = 10;
        $maxConnections = 7;
        // see above for pricing changes
        // $maxPot = 62.30;
        $maxPot = 63.74;
        $this->assertEquals($connectionValue, $data['quotes'][0]['connection_value']);
        $this->assertEquals($maxConnections, $data['quotes'][0]['max_connections']);
        $this->assertEquals($maxPot, $data['quotes'][0]['max_pot']);

        $this->assertCount(4, $data['quotes'][0]['excesses']);
        $this->assertEquals('theft', $data['quotes'][0]['excesses'][1]['type']);
        $this->assertEquals('150.00', $data['quotes'][0]['excesses'][1]['amount']);
        $this->assertEquals('damage', $data['quotes'][0]['excesses'][2]['type']);
        $this->assertEquals('150.00', $data['quotes'][0]['excesses'][2]['amount']);

        $this->assertCount(4, $data['quotes'][0]['picsure_excesses']);
        $this->assertEquals('theft', $data['quotes'][0]['picsure_excesses'][1]['type']);
        $this->assertEquals('70.00', $data['quotes'][0]['picsure_excesses'][1]['amount']);
        $this->assertEquals('damage', $data['quotes'][0]['picsure_excesses'][2]['type']);
        $this->assertEquals('50.00', $data['quotes'][0]['picsure_excesses'][2]['amount']);
    }

    public function testQuoteMemoryOptions()
    {
        $crawler = self::$client->request('GET', '/api/v1/quote?make=Apple&device=iPhone%206');
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertTrue(count($data['quotes']) > 1);
        // Make sure we're not returning all the quotes
        $this->assertTrue(count($data['quotes']) < 10);
    }

    public function testQuoteInactivePhonePreLaunch()
    {
        $crawler = self::$client->request('GET', '/api/v1/quote?make=Apple&device=iPhone%204');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_QUOTE_EXPIRED);

        $crawler = self::$client->request('GET', '/api/v1/quote?make=Apple&device=iPhone');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_QUOTE_PHONE_UNKNOWN);
    }

    public function testQuoteInactivePhoneMvp()
    {
        $crawler = self::$client->request('GET', '/api/v1/quote?make=Apple&device=iPhone%204&rooted=false&debug=true');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_QUOTE_EXPIRED);

        $crawler = self::$client->request('GET', '/api/v1/quote?make=Apple&device=iPhone&rooted=false&debug=true');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_QUOTE_PHONE_UNKNOWN);
    }

    public function testQuoteUnknownDevicePreLaunch()
    {
        $crawler = self::$client->request('GET', '/api/v1/quote?make=One&device=foo&debug=true');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_QUOTE_PHONE_UNKNOWN);
    }

    public function testQuotePartialUpcoming()
    {
        $crawler = self::$client->request('GET', '/api/v1/quote?make=Apple&device=upcoming-z&rooted=false&debug=true');
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(true, $data['memory_found']);
        $this->assertEquals(false, $data['different_make']);
        $this->assertEquals(false, $data['rooted']);
        $found64 = false;
        $found256 = false;
        foreach ($data['quotes'] as $quote) {
            if ($quote['phone']['memory'] == 64) {
                $this->assertEquals(1, $quote['can_purchase']);
                $found64 = true;
            } elseif ($quote['phone']['memory'] == 256) {
                $this->assertEquals(0, $quote['can_purchase']);
                $found256 = true;
            }
        }
        $this->assertTrue($found64);
        $this->assertTrue($found256);
    }

    public function testQuoteUnknownDeviceMvp()
    {
        $crawler = self::$client->request('GET', '/api/v1/quote?make=One&device=foo&rooted=false&debug=true');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_QUOTE_PHONE_UNKNOWN);
    }

    public function testQuoteKnownDeviceKnownMemory()
    {
        $crawler = self::$client->request('GET', '/api/v1/quote?make=OnePlus&device=A0001&memory=15.5&debug=true');
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(true, $data['memory_found']);
        $this->assertEquals(false, $data['different_make']);
        $this->assertEquals(false, $data['rooted']);
    }
    
    public function testQuoteKnownDeviceTooMuchMemory()
    {
        $crawler = self::$client->request(
            'GET',
            '/api/v1/quote?make=OnePlus&device=A0001&memory=65&rooted=false&debug=true'
        );
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(false, $data['memory_found']);
        $this->assertEquals(false, $data['different_make']);
        $this->assertEquals(false, $data['rooted']);
    }

    public function testQuoteKnownDeviceRooted()
    {
        $crawler = self::$client->request(
            'GET',
            '/api/v1/quote?make=OnePlus&device=A0001&memory=63&rooted=true&debug=true'
        );
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_QUOTE_UNABLE_TO_INSURE);
    }

    public function testQuoteKnownDeviceDifferentMake()
    {
        $crawler = self::$client->request(
            'GET',
            '/api/v1/quote?make=Apple&device=A0001&memory=63&rooted=false&debug=true'
        );
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(true, $data['different_make']);
    }

    public function testQuoteKnownDeviceSameMake()
    {
        $crawler = self::$client->request(
            'GET',
            '/api/v1/quote?make=Apple&device=iPhone8,2&memory=63&rooted=false&debug=true'
        );
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(false, $data['different_make']);
    }

    public function testQuoteRecordsStats()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('quote-stat', $this),
            'barfoo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $url = '/api/v1/quote?device=A0001&memory=63&rooted=true&debug=true&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422);
        $date = \DateTime::createFromFormat('U', time());
        $deviceKey = sprintf('stats:%s:query:%s', $date->format('Y-m-d'), 'A0001');
        $this->assertGreaterThan(0, self::$redis->get($deviceKey));
        $this->assertGreaterThan(0, self::$redis->get('stats:rooted:A0001'));
        $this->assertTrue(self::$redis->exists('stats:rooted:A0001') == 1);
    }

    // referral

    /**
     *
     */
    public function testReferralInvalid()
    {
        $crawler = self::$client->request('GET', '/api/v1/referral?email=abc');
        $data = $this->verifyResponse(422);
    }

    public function testReferral()
    {
        $user = static::createUser(self::$userManager, 'referral@api.bar.com', 'bar');

        $crawler = self::$client->request('GET', sprintf('/api/v1/referral?email=%s', $user->getEmail()));
        $data = $this->verifyResponse(200);
        $this->assertContains("https://goo.gl", $data['url']);
    }

    public function testReferralValidation()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = self::$client->request('GET', sprintf('/api/v1/referral?email[$ne]=1'));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testReferralCreate()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, 'origin@api.bar.com', 'bar');
        $userReferred = static::createUser(self::$userManager, 'referred@api.bar.com', 'bar');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/referral', array(
            'email' => $userReferred->getEmail(),
            'referral_code' => $user->getId(),
        ));
        $data = $this->verifyResponse(200);
        $this->assertTrue(mb_strlen($data['url']) > 0);

        // For some reason, querying with the same client/dm is not updating getting the latest record
        self::$client = static::createClient();
        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(User::class);
        /** @var User $fooUser */
        $fooUser = $repo->findOneBy(['email' => 'referred@api.bar.com']);
        $this->assertTrue($fooUser->getReferred()->getId() === $user->getId());
    }

    public function testReferralCreateInvalid()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/referral', array(
            'email' => 'foo',
            'referral_code' => 'foo',
        ));
        $data = $this->verifyResponse(422);
    }

    // reset

    /**
     *
     */
    public function testReset()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('user-reset', $this), 'bar');
        $this->assertNull($user->getConfirmationToken());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/reset', array(
            'email' => static::generateEmail('user-reset', $this)
        ));
        $data = $this->verifyResponse(200);

        // New DM required as some type of caching is occurring
        $repo = $this->getNewDocumentManager()->getRepository(User::class);
        /** @var User $queryUser */
        $queryUser = $repo->find($user->getId());
        $this->assertTrue(mb_strlen($queryUser->getConfirmationToken()) > 5);
    }

    public function testResetNoUser()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/reset', array(
            'email' => 'foo'
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_ABSENT);
    }

    public function testResetUserLocked()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('user-locked', $this), 'bar');
        $user->setLocked(true);
        self::$dm->flush();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/reset', array(
            'email' => static::generateEmail('user-locked', $this)
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_SUSPENDED);
    }

    public function testResetClearsLogin()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $userA = static::createUser(self::$userManager, static::generateEmail('user-reset-clearA', $this), 'bar');
        $userB = static::createUser(self::$userManager, static::generateEmail('user-reset-clearB', $this), 'bar');
        $this->assertNull($userA->getConfirmationToken());
        $this->assertNull($userB->getConfirmationToken());

        for ($i = 1; $i <= ceil(RateLimitService::$maxRequests[RateLimitService::DEVICE_TYPE_LOGIN] / 2); $i++) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
                'email' => static::generateEmail('user-reset-clearA', $this),
                'password' => 'foo'
            ]));
            $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_EXISTS);
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
                'email' => static::generateEmail('user-reset-clearB', $this),
                'password' => 'foo'
            ]));
            $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_EXISTS);
        }
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => static::generateEmail('user-reset-clearA', $this),
            'password' => 'foo'
        ]));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_TOO_MANY_REQUESTS);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/reset', array(
            'email' => static::generateEmail('user-reset-clearA', $this)
        ));
        $data = $this->verifyResponse(200);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => static::generateEmail('user-reset-clearA', $this),
            'password' => 'foo'
        ]));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_EXISTS);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => static::generateEmail('user-reset-clearA', $this),
            'password' => 'bar'
        ]));
        $data = $this->verifyResponse(200);
    }

    // GET /scode/{id}

    /**
     *
     */
    public function testGetSCode()
    {
        $policy = static::createUserPolicy(true);
        $policy->getUser()->setEmail(static::generateEmail('testGetSCode', $this));
        $policy->createAddSCode(rand(1, 999999));
        static::$dm->persist($policy->getUser());
        static::$dm->persist($policy);
        static::$dm->flush();

        $sCode = $policy->getStandardSCode();
        $this->assertNotNull($sCode);

        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/scode/%s?_method=GET', $sCode->getCode());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);
        $this->assertEquals(8, mb_strlen($getData['code']));
        $this->assertEquals(SCode::TYPE_STANDARD, $getData['type']);
        $this->assertEquals(true, $getData['active']);

        $shareUrl = self::$router->generate('scode', ['code' => $sCode->getCode()]);
        $this->assertTrue(mb_stripos($getData["share_link"], $shareUrl) >= 0);
        $this->assertTrue(mb_stripos($getData["share_link"], 'http') >= 0);
    }

    public function testGetInactiveSCode()
    {
        $s = new SCode();
        $s->setActive(false);
        static::$dm->persist($s);
        static::$dm->flush();

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(SCode::class);
        /** @var SCode $sCode */
        $sCode = $repo->findOneBy(['active' => false, 'type' => 'standard']);
        $this->assertNotNull($sCode);

        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/scode/%s?_method=GET', $sCode->getCode());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testGetRewardSCode()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$reward->getUser());
        $url = sprintf('/api/v1/scode/%s?_method=GET', self::$reward->getSCode()->getCode());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(SCode::TYPE_REWARD, $data['type']);
    }

    // token

    /**
     *
     */
    public function testTokenOk()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('token', $this), 'bar');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken()
        ));
        $data = $this->verifyResponse(200);
        $this->assertTrue(mb_strlen($data['token']) > 20);
    }

    public function testTokenBad()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('badtoken', $this), 'bar');
        static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => sprintf('%s-bad', $user->getToken()),
            'cognito_id' => '123'
        ));
        $this->verifyResponse(403);
    }

    public function testTokenMissing()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/token', []);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testTokenOkUserDisabled()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('token3', $this), 'bar');

        $user->setEnabled(false);
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken()
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_RESET_PASSWORD);
    }

    public function testTokenOkUserLocked()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('token4', $this), 'bar');

        $user->setLocked(true);
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken()
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_SUSPENDED);
    }

    public function testTokenRateLimited()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('token-ratelimit', $this), 'bar');

        for ($i = 1; $i <= RateLimitService::$maxRequests[RateLimitService::DEVICE_TYPE_TOKEN] + 1; $i++) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/token', [
                'token' => $user->getToken()
            ]);
        }
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_TOO_MANY_REQUESTS);
    }

    // user

    /**
     *
     */
    public function testUserDuplicate()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, 'dup-user@api.bar.com', 'bar');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'dup-user@api.bar.com'
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_EXISTS);
    }

    public function testUserFacebookDuplicate()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, self::generateEmail('dup-fb', $this), 'bar');
        $user->setFacebookId(rand(1, 999999));
        self::$dm->flush();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => self::generateEmail('dup-fb-diff', $this),
            'facebook_id' => $user->getFacebookId(),
            'facebook_access_token' => 'foo',
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_EXISTS);
    }

    public function testUserCreate()
    {
        $this->expectNoUserEmailChangeEvent();
        $cognitoIdentityId = $this->getUnauthIdentity();

        $birthday = new \DateTime('1980-01-01');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'api-new-user@api.bar.com',
            'birthday' => $birthday->format(\DateTime::ATOM),
            'first_name' => 'foo',
            'last_name' => 'bar',
            'referer' => 'utm_source=(not%20set)&utm_medium=(not%20set)',
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals('api-new-user@api.bar.com', $data['email']);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->findOneBy(['email' => 'api-new-user@api.bar.com']);
        $this->assertNotNull($user);
        $this->assertEquals($cognitoIdentityId, $user->getIdentityLog()->getCognitoId());
        $this->assertEquals($birthday->format(\DateTime::ATOM), $user->getBirthday()->format(\DateTime::ATOM));
        $this->assertEquals('Foo', $user->getFirstName());
        $this->assertEquals('Bar', $user->getLastName());
        $this->assertTrue(mb_strlen($data['intercom_token']['android_hash']) > 10, json_encode($data));
        $this->assertTrue(mb_strlen($data['intercom_token']['ios_hash']) > 10);
        $this->assertNotNull($user->getFirstLoginInApp());
    }

    public function testUserBadName()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $birthday = new \DateTime('1980-01-01');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('user-bad-name', $this),
            'birthday' => $birthday->format(\DateTime::ATOM),
            'first_name' => 'foo$',
            'last_name' => 'bar$',
        ));
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->findOneBy(['email' => static::generateEmail('user-bad-name', $this)]);
        $this->assertNotNull($user);
        $this->assertEquals($cognitoIdentityId, $user->getIdentityLog()->getCognitoId());
        $this->assertEquals('Foo', $user->getFirstName());
        $this->assertEquals('Bar', $user->getLastName());
    }

    public function testUserDoubleBarrelled()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $birthday = new \DateTime('1980-01-01');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('user-double-barrelled', $this),
            'birthday' => $birthday->format(\DateTime::ATOM),
            'first_name' => 'foo',
            'last_name' => 'bar-foo',
        ));
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->findOneBy(['email' => static::generateEmail('user-double-barrelled', $this)]);
        $this->assertNotNull($user);
        $this->assertEquals($cognitoIdentityId, $user->getIdentityLog()->getCognitoId());
        $this->assertEquals('Foo', $user->getFirstName());
        $this->assertEquals('Bar-foo', $user->getLastName());
    }

    public function testUserSpaceInName()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $birthday = new \DateTime('1980-01-01');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('testUserSpaceInName', $this),
            'birthday' => $birthday->format(\DateTime::ATOM),
            'first_name' => 'foo bar',
            'last_name' => 'barfoo foofoo',
        ));
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->findOneBy(['email' => static::generateEmail('testUserSpaceInName', $this)]);
        $this->assertNotNull($user);
        $this->assertEquals($cognitoIdentityId, $user->getIdentityLog()->getCognitoId());
        $this->assertEquals('Foobar', $user->getFirstName());
        $this->assertEquals('Barfoofoofoo', $user->getLastName());
    }

    public function testUserCreateBadReferer()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $birthday = new \DateTime('1980-01-01');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('user-bad-referer', $this),
            'birthday' => $birthday->format(\DateTime::ATOM),
            'first_name' => 'foo',
            'last_name' => 'bar',
            // exepect $ to be stripped
            'referer' => 'utm_source=$(not%20set)&utm_medium=(not%20set)',
        ));
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($data['id']);
        $this->assertNotNull($user);
        $this->assertEquals('utm_source=(not%20set)&utm_medium=(not%20set)', $user->getReferer());
    }

    public function testUserValidation()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $birthday = new \DateTime('1980-01-01');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => $this->generateEmail('user-validation', $this),
            'first_name' => ['$ne' => '1'],
            'last_name' => 'bar',
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUserCreateIp()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'api-ip-user@api.bar.com'
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals('api-ip-user@api.bar.com', $data['email']);

        $repo = self::$dm->getRepository(User::class);
        /** @var User $fooUser */
        $fooUser = $repo->findOneBy(['email' => 'api-ip-user@api.bar.com']);
        $this->assertTrue($fooUser != null);
        $this->assertEquals('62.253.24.189', $fooUser->getIdentityLog()->getIp());
        $this->assertEquals('GB', $fooUser->getIdentityLog()->getCountry());
        $this->assertEquals([-0.13,51.5], $fooUser->getIdentityLog()->getLoc()->getCoordinates());
    }

    public function testUserCreateCampaign()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('create-campaign', $this),
            'referer' => 'foo',
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals(mb_strtolower(static::generateEmail('create-campaign', $this)), $data['email']);

        $repo = self::$dm->getRepository(User::class);
        /** @var User $fooUser */
        $fooUser = $repo->findOneBy([
            'emailCanonical' => mb_strtolower(static::generateEmail('create-campaign', $this))
        ]);
        $this->assertTrue($fooUser != null);
        $this->assertEquals('foo', $fooUser->getReferer());
    }

    public function testPreLaunchUserCanOverwrite()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('create-prelaunch', $this),
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals(mb_strtolower(static::generateEmail('create-prelaunch', $this)), $data['email']);
        $token = $data['user_token']['token'];

        $repo = self::$dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy([
            'emailCanonical' => mb_strtolower(static::generateEmail('create-prelaunch', $this))
        ]);
        $this->assertTrue($user != null);
        $this->assertNull($user->getLastLogin());

        $user->setPreLaunch(true);
        self::$dm->flush();

        $mobile = static::generateRandomMobile();

        // now duplicate the create
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('create-prelaunch', $this),
            'mobile_number' => $mobile,
        ));
        $data = $this->verifyResponse(200);
        // Token should have changed
        $this->assertNotEquals($token, $data['user_token']['token']);

        // New DM required as some type of caching is occurring
        $repo = $this->getNewDocumentManager()->getRepository(User::class);
        $userUpdated = $repo->findOneBy([
            'emailCanonical' => mb_strtolower(static::generateEmail('create-prelaunch', $this))
        ]);
        $this->assertTrue($userUpdated != null);
        $this->assertNotNull($userUpdated->getLastLogin());
        $this->assertEquals($mobile, $userUpdated->getMobileNumber());
    }

    public function testPreLaunchUserLoggedInWillNotOverwrite()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('create-prelaunch-loggedin', $this),
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals(mb_strtolower(static::generateEmail('create-prelaunch-loggedin', $this)), $data['email']);
        $token = $data['user_token']['token'];

        $repo = self::$dm->getRepository(User::class);
        /** @var User $user */
        $user = $repo->findOneBy([
            'emailCanonical' => mb_strtolower(static::generateEmail('create-prelaunch-loggedin', $this))
        ]);
        $this->assertTrue($user != null);
        $this->assertNull($user->getLastLogin());

        $user->setLastLogin(\DateTime::createFromFormat('U', time()));
        $user->setPreLaunch(true);
        self::$dm->flush();

        // now duplicate the create
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('create-prelaunch', $this),
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_EXISTS);
    }

    public function testUserWithMobileCreate()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $mobile = static::generateRandomMobile();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('api-new-user-mobile', $this),
            'mobile_number' => $mobile
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals(mb_strtolower(static::generateEmail('api-new-user-mobile', $this)), $data['email']);
        $this->assertEquals($mobile, $data['mobile_number']);
    }

    public function testUserCreateWithDupMobile()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $mobile = static::generateRandomMobile();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('user-create-dup-mobile', $this),
            'mobile_number' => $mobile
        ));
        $data = $this->verifyResponse(200);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('user-create-dup-mobile2', $this),
            'mobile_number' => self::transformMobile($mobile)
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_EXISTS);
    }

    public function testUserNoEmail()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array());
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testUserInvalidEmail()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'abc'
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUserInvalidMobile()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('invalid-mobile', $this),
            'mobile_number' => '+44770090000'
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUserInvalidBirthday()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('invalid-birthday', $this),
            'birthday' => '+44770'
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUserTooYoung()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $now = \DateTime::createFromFormat('U', time());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('young-birthday', $this),
            'birthday' => sprintf('%d-01-01T00:00:00Z', $now->format('Y')),
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_TOO_YOUNG);
    }

    public function testUserUkMobile()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $extension = rand(1, 99999);
        $ukMobile = sprintf('07700 9%05d', $extension);
        $normalizedMobile = sprintf('+4477009%05d', $extension);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('uk-mobile', $this),
            'mobile_number' => $ukMobile,
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals($normalizedMobile, $data['mobile_number']);
    }

    public function testUserCreateSCode()
    {
        $dm = $this->getDocumentManager(true);
        $scode = new SCode();
        $dm->persist($scode);
        $dm->flush();

        $cognitoIdentityId = $this->getUnauthIdentity();

        $birthday = new \DateTime('1980-01-01');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'api-new-user-scode@api.bar.com',
            'birthday' => $birthday->format(\DateTime::ATOM),
            'first_name' => 'foo',
            'last_name' => 'bar',
            'scode' => $scode->getCode(),
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals('api-new-user-scode@api.bar.com', $data['email']);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->findOneBy(['email' => 'api-new-user-scode@api.bar.com']);
        $this->assertNotNull($user);
        $this->assertEquals($scode->getId(), $user->getAcceptedSCode()->getId());
        $this->assertEquals(Lead::LEAD_SOURCE_SCODE, $user->getLeadSource());
    }

    public function testUserCreateInactiveSCode()
    {
        $dm = $this->getDocumentManager(true);
        $scode = new SCode();
        $scode->setActive(false);
        $dm->persist($scode);
        $dm->flush();

        $cognitoIdentityId = $this->getUnauthIdentity();

        $birthday = new \DateTime('1980-01-01');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'api-new-user-inactive-scode@api.bar.com',
            'birthday' => $birthday->format(\DateTime::ATOM),
            'first_name' => 'foo',
            'last_name' => 'bar',
            'scode' => $scode->getCode(),
        ));
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    // version

    /**
     *
     */
    public function testVersionTooOldiOS()
    {
        $crawler = self::$client->request('GET', '/api/v1/version?platform=ios&version=1.5.8');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_UPGRADE_APP);
    }

    public function testVersionTooOldAndroid()
    {
        $crawler = self::$client->request('GET', '/api/v1/version?platform=android&version=1.5.10.0');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_UPGRADE_APP);
    }

    public function testVersionOkiOS()
    {
        $crawler = self::$client->request('GET', '/api/v1/version?platform=ios&version=1.5.36');
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);
    }

    public function testVersionOkAndroid()
    {
        $crawler = self::$client->request('GET', '/api/v1/version?platform=android&version=1.5.37.0');
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);
    }

    public function testVersionValidation()
    {
        $crawler = self::$client->request('GET', '/api/v1/version?platform=ios&version[$ne]=1');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testVersionMissingParam()
    {
        $crawler = self::$client->request('GET', '/api/v1/version?platform=ios');
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = self::$client->request('GET', '/api/v1/version?version=0.0.1');
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testVersionInvalid()
    {
        $crawler = self::$client->request('GET', '/api/v1/version?platform=ios&version=0.0.0');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_UPGRADE_APP);
    }

    public function testVersionNotRegulated()
    {
        /** @var Client $redis */
        $redis = $this->getContainer(true)->get('snc_redis.default');
        $redis->set('ERROR_NOT_YET_REGULATED', 1);
        $crawler = self::$client->request('GET', '/api/v1/version?platform=ios&version=0.0.0');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_YET_REGULATED);
        $redis->del(['ERROR_NOT_YET_REGULATED']);
    }

    public function testVersionUpgradeNeeded()
    {
        /** @var Client $redis */
        $redis = $this->getContainer(true)->get('snc_redis.default');
        $redis->sadd('UPGRADE_APP_VERSIONS_ios', ['0.0.1']);
        $crawler = self::$client->request('GET', '/api/v1/version?platform=ios&version=0.0.1');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_UPGRADE_APP);
        $redis->del(['UPGRADE_APP_VERSIONS_ios']);
    }

    public function testVersionDevice()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $url = '/api/v1/version?platform=ios&version=2.0.1&device=iPhone%205c&memory=32&uuid=1&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);
        /** @var Client $redis */
        $redis = $this->getContainer(true)->get('snc_redis.default');
        $data = json_decode($redis->get(sprintf("device:%s", $cognitoIdentityId)), true);
        $this->assertEquals('ios', $data['platform']);
        $this->assertEquals('2.0.1', $data['version']);
        $this->assertEquals(1, $data['uuid']);
        $this->assertEquals('iPhone 5c', $data['device']);
        $this->assertEquals(32, $data['memory']);
    }

    public function testVersionDeviceUser()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testVersionDeviceUser', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $url = '/api/v1/version?platform=ios&version=2.0.1&device=iPhone%206s&memory=64&uuid=1&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(User::class);
        /** @var User $updatedUser */
        $updatedUser = $repo->find($user->getId());
        $identityLog = $updatedUser->getLatestMobileIdentityLog();

        $this->assertEquals('ios', $identityLog->getPlatform());
        $this->assertEquals('2.0.1', $identityLog->getVersion());
        $this->assertEquals(1, $identityLog->getUuid());
        $this->assertNotNull($identityLog->getPhone());
    }

    public function testVersionDeviceUserPolicy()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testVersionDeviceUserPolicy', $this),
            'foo'
        );
        $phone = self::getRandomPhone(self::$dm);
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $cognitoIdentityId = $this->getAuthUser($user);

        $url = sprintf(
            '/api/v1/version?platform=ios&version=2.0.1&device=%s&memory=%d&uuid=1&_method=GET',
            $phone->getDevices()[0],
            $phone->getMemory()
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken()
        ));
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(User::class);
        /** @var User $updatedUser */
        $updatedUser = $repo->find($user->getId());
        $isVerified = false;
        foreach ($updatedUser->getAllPolicies() as $policy) {
            if ($policy->getPhoneVerified()) {
                $isVerified = true;
            }
        }
        $this->assertTrue($isVerified);
    }

    public function testVersion2NoInclude()
    {
        $crawler = self::$client->request('GET', '/api/v1/version/v2?platform=ios&version=2.0.1');
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);
        $this->assertFalse(isset($data['feature_flags']));
    }

    public function testVersion2Include()
    {
        $crawler = self::$client->request('GET', '/api/v1/version/v2?platform=ios&version=2.0.1&include=feature-flags');
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);
        $this->assertTrue(count($data['feature_flags']['flags']) > 0);
    }

    public function testReplay()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/test/replay', array('data' => [
            'foo' => 'bar'
        ]));
        $data = $this->verifyResponse(200);

        // replay fail
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/test/replay', array('data' => [
            'foo' => 'bar'
        ]));
        $data = $this->verifyResponse(500);

        // different url should not trigger replay
        $url = '/api/v1/test/replay?foo=bar';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, array('data' => [
            'foo' => 'bar'
        ]));
        $data = $this->verifyResponse(200);

        // different contents should not trigger replay
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/test/replay', array('data' => [
            'bar' => 'foo'
        ]));
        $data = $this->verifyResponse(200);
    }
}
