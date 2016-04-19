<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-net
 */
class ApiControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    public function tearDown()
    {
    }

    // Manual test

    /**
     * This is a test that can be manually run by uncommenting exception in login
     * Purely to test that boilerplate exception login & return codes work
     */
    /*
    public function testManual()
    {
        $client = static::createClient();
        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array('body' => array('username' => 'foo', 'password' => 'bar')))
        );
        $this->assertEquals(500, $client->getResponse()->getStatusCode());
    }
    */

    // address

    /**
     *
     */
    public function testAddress()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/address?postcode=BX11LT');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals("so-sure Test Address Line 1", $data['line1']);
        $this->assertEquals("so-sure Test Address Line 2", $data['line2']);
        $this->assertEquals("so-sure Test City", $data['city']);
        $this->assertEquals("BX1 1LT", $data['postcode']);
    }

    public function testAddressRateLimited()
    {
        $client = static::createClient();

        // Run enough to trigger cognito rate limit
        for ($i = 0; $i < 4; $i++) {
            $crawler = $client->request('GET', '/api/v1/address?postcode=BX11LT');
        }

        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, $data['code']);
    }

    /* TODO: Consider moving to a different type of test.
     * Note that once we're out of test mode mid-apr 2016,
     * then it should be possible to use this test
    public function testAddress()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/address?postcode=WR53DA');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals("Lock Keepers Cottage", $data['line1']);
        $this->assertEquals("Basin Road", $data['line2']);
        $this->assertEquals("Worcester", $data['city']);
        $this->assertEquals("WR5 3DA", $data['postcode']);
    }
    */

    /**
     *
     */
    public function testAddressReqParam()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/address?postcode=');
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    // login

    /**
     *
     */
    public function testLoginNoUser()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => 'foo',
            'password' => 'bar'
        ]));
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(101, $data['code']);
    }

    public function testLoginMissingPasswordParam()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => 'foo'
        ]));
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    public function testLoginMissingUserParam()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'password' => 'bar'
        ]));
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }


    public function testLoginBadPassword()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), 'badfoo@api.bar.com', 'bar');
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => 'badfoo@api.bar.com',
            'password' => 'barfoo'
        ]));
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(100, $data['code']);
    }

    public function testLoginOk()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), 'foo@api.bar.com', 'bar');
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => 'foo@api.bar.com',
            'password' => 'bar'
        ]));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('foo@api.bar.com', $data['email']);
        $this->assertTrue(strlen($data['cognito_token']['id']) > 10);
        $this->assertTrue(strlen($data['cognito_token']['token']) > 10);
    }

    public function testLoginNoUserType()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', []);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    public function testLoginFacebookMissingParam()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser(
            $this->getUserManager($client),
            static::generateEmail('facebook-missing', $this),
            'bar'
        );

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('facebook_user' => [
            'facebook_id' => 'foo',
        ]));
        $this->assertEquals(400, $client->getResponse()->getStatusCode());

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('facebook_user' => [
            'facebook_access_token' => 'foo',
        ]));
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    public function testLoginFacebookInvalidToken()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser(
            $this->getUserManager($client),
            static::generateEmail('facebook-invalid-token', $this),
            'bar'
        );
        $user->setFacebookId(1);
        $dm = $this->getManager($client);
        $dm->flush();

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('facebook_user' => [
            'facebook_id' => '1',
            'facebook_access_token' => 'foo',
        ]));
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
    }

    public function testLoginOkUserExpired()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), static::generateEmail('token1', $this), 'bar');

        $user->setExpired(true);
        $dm = $this->getManager($client);
        $dm->flush();

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => static::generateEmail('login1', $this),
            'password' => 'bar'
        ]));
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_USER_ABSENT, $data['code']);
    }

    public function testLoginOkUserDisabled()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), static::generateEmail('login2', $this), 'bar');

        $user->setEnabled(false);
        $dm = $this->getManager($client);
        $dm->flush();

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => static::generateEmail('login2', $this),
            'password' => 'bar'
        ]));
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_USER_RESET_PASSWORD, $data['code']);
    }

    public function testLoginOkUserLocked()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), static::generateEmail('login3', $this), 'bar');

        $user->setLocked(true);
        $dm = $this->getManager($client);
        $dm->flush();

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
            'email' => static::generateEmail('login3', $this),
            'password' => 'bar'
        ]));
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_USER_SUSPENDED, $data['code']);
    }

    public function testLoginRateLimited()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        for ($i = 1; $i <= RateLimitService::$maxRequests[RateLimitService::TYPE_LOGIN] + 1; $i++) {
            $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/login', array('email_user' => [
                'email' => static::generateEmail('invalid-user', $this),
                'password' => 'invalid'
            ]));
        }
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, $data['code']);
    }

    // ping

    /**
     *
     */
    public function testPing()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = $client->request('GET', '/api/v1/ping');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(0, $data['code']);
    }

    // quote
    
    /**
     *
     */
    public function testQuoteUnknown()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = $client->request('GET', '/api/v1/quote');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(false, $data['device_found']);
        $this->assertTrue(count($data['quotes']) > 2);
        // Make sure we're not returning all the quotes
        $this->assertTrue(count($data['quotes']) < 10);
    }

    public function testQuoteX3()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/quote?device=x3');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
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
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/quote?device=iPhone%206');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(true, $data['device_found']);
        $this->assertTrue(count($data['quotes']) > 1);
        // Make sure we're not returning all the quotes
        $this->assertTrue(count($data['quotes']) < 10);
    }

    public function testQuoteUnknownDevice()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/quote?device=foo&debug=true');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(false, $data['device_found']);
        $this->assertEquals(true, $data['memory_found']);
    }

    public function testQuoteKnownDeviceKnownMemory()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/quote?device=A0001&memory=15.5&debug=true');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(true, $data['memory_found']);
    }
    
    public function testQuoteKnownDeviceUnKnownMemory()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/quote?device=A0001&memory=65&debug=true');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(true, $data['device_found']);
        $this->assertEquals(false, $data['memory_found']);
    }

    // referral
    
    /**
     *
     */
    public function testReferralInvalid()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/referral?email=abc');
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
    }

    public function testReferral()
    {
        $client = static::createClient();
        $user = static::createUser($this->getUserManager($client), 'referral@api.bar.com', 'bar');

        $crawler = $client->request('GET', sprintf('/api/v1/referral?email=%s', $user->getEmail()));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertContains("http://goo.gl", $data['url']);
    }

    public function testReferralCreate()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), 'origin@api.bar.com', 'bar');
        $userReferred = static::createUser($this->getUserManager($client), 'referred@api.bar.com', 'bar');
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/referral', array(
            'email' => $userReferred->getEmail(),
            'referral_code' => $user->getId(),
        ));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue(strlen($data['url']) > 0);

        $dm = $this->getManager($client);
        $repo = $dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'referred@api.bar.com']);
        $this->assertTrue($fooUser->getReferred()->getId() === $user->getId());
    }

    public function testReferralCreateInvalid()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/referral', array(
            'email' => 'foo',
            'referral_code' => 'foo',
        ));
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
    }

    // sns

    /**
     *
     */
    public function testSns()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/sns', array(
            // @codingStandardsIgnoreStart
            'endpoint' => 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/344008b8-a266-3d7b-baa4-f1e8cf9fc16e'
            // @codingStandardsIgnoreEnd
        ));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testSnsMissingEndpoint()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/sns', array());
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    // token

    /**
     *
     */
    public function testTokenOk()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), static::generateEmail('token', $this), 'bar');
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken()
        ));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue(strlen($data['token']) > 20);
    }

    public function testTokenBad()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), static::generateEmail('badtoken', $this), 'bar');
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken() + 'bad'
        ));
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
    }

    public function testTokenMissing()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/token', []);
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    public function testTokenOkUserExpired()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), static::generateEmail('token2', $this), 'bar');

        $user->setExpired(true);
        $dm = $this->getManager($client);
        $dm->flush();

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken()
        ));
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_USER_ABSENT, $data['code']);
    }

    public function testTokenOkUserDisabled()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), static::generateEmail('token3', $this), 'bar');

        $user->setEnabled(false);
        $dm = $this->getManager($client);
        $dm->flush();

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken()
        ));
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_USER_RESET_PASSWORD, $data['code']);
    }

    public function testTokenOkUserLocked()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), static::generateEmail('token4', $this), 'bar');

        $user->setLocked(true);
        $dm = $this->getManager($client);
        $dm->flush();

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/token', array(
            'token' => $user->getToken()
        ));
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_USER_SUSPENDED, $data['code']);
    }

    // user

    /**
     *
     */
    public function testUserDuplicate()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), 'dup-user@api.bar.com', 'bar');
        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'dup-user@api.bar.com'
        ));
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
    }

    public function testUserCreate()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'api-new-user@api.bar.com'
        ));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('api-new-user@api.bar.com', $data['email']);

        $dm = $this->getManager($client);
        $repo = $dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'api-new-user@api.bar.com']);
        $this->assertTrue($fooUser !== null);
        $cognitoIdentityValue = sprintf("cognitoIdentityId=%s,", $fooUser->getCognitoId());
        $this->assertTrue(stripos($cognitoIdentityId, $cognitoIdentityValue) !== false);
    }

    public function testUserCreateIp()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'api-ip-user@api.bar.com'
        ));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('api-ip-user@api.bar.com', $data['email']);

        $dm = $this->getManager($client);
        $repo = $dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'api-ip-user@api.bar.com']);
        $this->assertTrue($fooUser !== null);
        $this->assertEquals('62.253.24.189', $fooUser->getSignupIp());
        $this->assertEquals('GB', $fooUser->getSignupCountry());
        $this->assertEquals([-0.13,51.5], $fooUser->getSignupLoc()->coordinates);
    }
    
    public function testUserWithMobileCreate()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'api-new-user-mobile@api.bar.com',
            'mobile_number' => '+447700900000'
        ));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('api-new-user-mobile@api.bar.com', $data['email']);
        $this->assertEquals('+447700900000', $data['mobile_number']);
    }

    public function testUserNoEmail()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/user', array(
        ));
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    public function testUserInvalidEmail()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => 'abc'
        ));
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $data['code']);
    }

    public function testUserInvalidMobile()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('invalid-mobile', $this),
            'mobile_number' => '+44770090000'
        ));
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $data['code']);
    }

    public function testUserUkMobile()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = static::postRequest($client, $cognitoIdentityId, '/api/v1/user', array(
            'email' => static::generateEmail('uk-mobile', $this),
            'mobile_number' => '07700 900000'
        ));
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('+447700900000', $data['mobile_number']);
    }

    // version

    /**
     *
     */
    public function testVersionOk()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/version?platform=ios&version=0.0.1');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::SUCCESS, $data['code']);
    }

    public function testVersionMissingParam()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/version?platform=ios');
        $this->assertEquals(400, $client->getResponse()->getStatusCode());

        $crawler = $client->request('GET', '/api/v1/version?version=0.0.1');
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    public function testVersionInvalid()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/version?platform=ios&version=0.0.0');
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_UPGRADE_APP, $data['code']);
    }

    public function testVersionNotRegulated()
    {
        $client = static::createClient();
        $redis = $client->getContainer()->get('snc_redis.default');
        $redis->set('ERROR_NOT_YET_REGULATED', 1);
        $crawler = $client->request('GET', '/api/v1/version?platform=ios&version=0.0.0');
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_NOT_YET_REGULATED, $data['code']);
    }

    // helpers

    /**
     *
     */
    protected function getUnauthIdentity($client)
    {
        return static::getIdentityString($client->getContainer()->get('app.cognito.identity')->getId());
    }
    
    protected function getManager($client)
    {
        return $client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
    }

    protected function getUserManager($client)
    {
        return $client->getContainer()->get('fos_user.user_manager');
    }
}
