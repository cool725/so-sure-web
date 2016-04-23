<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-net
 */
class ApiControllerTest extends BaseControllerTest
{
    // Manual test

    /**
     * This is a test that can be manually run by uncommenting exception in login
     * Purely to test that boilerplate exception login & return codes work
     */
    /*
    public function testManual()
    {
        $crawler = self::$client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('username' => 'foo', 'password' => 'bar')))
        );
        $data = $this->verifyResponse(500);
    }
    */

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
        $this->assertTrue(strlen($data['cognito_token']['id']) > 10);
        $this->assertTrue(strlen($data['cognito_token']['token']) > 10);
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
            'facebook_id' => '1',
            'facebook_access_token' => 'foo',
        ]));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_ABSENT);
    }

    public function testLoginOkUserExpired()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('token1', $this), 'bar');

        $user->setExpired(true);
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => static::generateEmail('login1', $this),
            'password' => 'bar'
        ]));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_ABSENT);
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
        $cognitoIdentityId = $this->getUnauthIdentity();

        for ($i = 1; $i <= RateLimitService::$maxRequests[RateLimitService::TYPE_LOGIN] + 1; $i++) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
                'email' => static::generateEmail('invalid-user', $this),
                'password' => 'invalid'
            ]));
        }
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_TOO_MANY_REQUESTS);
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

    // quote
    
    /**
     *
     */
    public function testQuoteUnknown()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = self::$client->request('GET', '/api/v1/quote');
        $data = $this->verifyResponse(200);
        $this->assertEquals(false, $data['device_found']);
        $this->assertTrue(count($data['quotes']) > 2);
        // Make sure we're not returning all the quotes
        $this->assertTrue(count($data['quotes']) < 10);
    }

    public function testQuoteX3()
    {
        $crawler = self::$client->request('GET', '/api/v1/quote?device=x3');
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(1, count($data['quotes']));
        $this->assertEquals(10, $data['quotes'][0]['connection_value']);

        $maxConnections = $data['quotes'][0]['max_connections'];
        $maxPot = $data['quotes'][0]['max_pot'];
        $this->assertTrue(5 <= $maxConnections);
        $this->assertTrue(9 >= $maxConnections);

        $this->assertTrue(50 <= $maxPot);
        $this->assertTrue(90 >= $maxPot);
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

    public function testQuoteUnknownDevice()
    {
        $crawler = self::$client->request('GET', '/api/v1/quote?make=One&device=foo&debug=true');
        $data = $this->verifyResponse(200);
        $this->assertEquals(false, $data['device_found']);
        $this->assertEquals(false, $data['different_make']);
        $this->assertEquals(true, $data['memory_found']);
        $this->assertEquals(false, $data['rooted']);
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
    
    public function testQuoteKnownDeviceUnKnownMemory()
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
            '/api/v1/quote?make=OnePlus&device=A0001&memory=65&rooted=true&debug=true'
        );
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(false, $data['memory_found']);
        $this->assertEquals(false, $data['different_make']);
        $this->assertEquals(true, $data['rooted']);
    }

    public function testQuoteKnownDeviceDifferentMake()
    {
        $crawler = self::$client->request(
            'GET',
            '/api/v1/quote?make=Apple&device=A0001&memory=65&rooted=false&debug=true'
        );
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(true, $data['different_make']);
    }

    public function testQuoteRecordsStats()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('quote-stat', $this),
            'barfoo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $url = '/api/v1/quote?device=A0001&memory=65&rooted=true&debug=true&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $date = new \DateTime();
        $deviceKey = sprintf('stats:%s:query:%s', $date->format('Y-m-d'), 'A0001');
        $this->assertGreaterThan(0, self::$redis->get($deviceKey));
        $this->assertTrue(self::$redis->exists('stats:rooted:A0001'));
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
        $this->assertContains("http://goo.gl", $data['url']);
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
        $this->assertTrue(strlen($data['url']) > 0);

        // For some reason, querying with the same client/dm is not updating getting the latest record
        $client = static::createClient();
        $dm = $client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(User::class);
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

    // sns

    /**
     *
     */
    public function testSns()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/sns', array(
            // @codingStandardsIgnoreStart
            'endpoint' => 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/344008b8-a266-3d7b-baa4-f1e8cf9fc16e'
            // @codingStandardsIgnoreEnd
        ));
        $data = $this->verifyResponse(200);
    }

    public function testSnsMissingEndpoint()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/sns', array());
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
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
        $this->assertTrue(strlen($data['token']) > 20);
    }

    public function testTokenBad()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('badtoken', $this), 'bar');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken() + 'bad'
        ));
        $data = $this->verifyResponse(403);
    }

    public function testTokenMissing()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/token', []);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testTokenOkUserExpired()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('token2', $this), 'bar');

        $user->setExpired(true);
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken()
        ));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_ABSENT);
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
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'api-new-user@api.bar.com'
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals('api-new-user@api.bar.com', $data['email']);

        $repo = self::$dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'api-new-user@api.bar.com']);
        $this->assertTrue($fooUser !== null);
        $cognitoIdentityValue = sprintf("cognitoIdentityId=%s,", $fooUser->getCognitoId());
        $this->assertTrue(stripos($cognitoIdentityId, $cognitoIdentityValue) !== false);
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
        $fooUser = $repo->findOneBy(['email' => 'api-ip-user@api.bar.com']);
        $this->assertTrue($fooUser !== null);
        $this->assertEquals('62.253.24.189', $fooUser->getSignupIp());
        $this->assertEquals('GB', $fooUser->getSignupCountry());
        $this->assertEquals([-0.13,51.5], $fooUser->getSignupLoc()->coordinates);
    }
    
    public function testUserWithMobileCreate()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'api-new-user-mobile@api.bar.com',
            'mobile_number' => '+447700900000'
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals('api-new-user-mobile@api.bar.com', $data['email']);
        $this->assertEquals('+447700900000', $data['mobile_number']);
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

    public function testUserUkMobile()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('uk-mobile', $this),
            'mobile_number' => '07700 900000'
        ));
        $data = $this->verifyResponse(200);
        $this->assertEquals('+447700900000', $data['mobile_number']);
    }

    // version

    /**
     *
     */
    public function testVersionOk()
    {
        $crawler = self::$client->request('GET', '/api/v1/version?platform=ios&version=0.0.1');
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);
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
        $redis = self::$client->getContainer()->get('snc_redis.default');
        $redis->set('ERROR_NOT_YET_REGULATED', 1);
        $crawler = self::$client->request('GET', '/api/v1/version?platform=ios&version=0.0.0');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_YET_REGULATED);
    }
}
