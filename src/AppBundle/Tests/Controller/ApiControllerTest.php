<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

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
        $crawler = $client->request('GET', '/api/v1/address?postcode=WR53DA');
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals("Lock Keepers Cottage", $data['line1']);
        $this->assertEquals("Basin Road", $data['line2']);
        $this->assertEquals("Worcester", $data['city']);
        $this->assertEquals("WR5 3DA", $data['postcode']);
    }

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
        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array('email' => 'foo', 'password' => 'bar'),
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(101, $data['code']);
    }

    public function testLoginMissingPasswordParam()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array('email' => 'foo'),
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }

    public function testLoginMissingUserParam()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array('password' => 'bar'),
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
    }


    public function testLoginBadPassword()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), 'badfoo@api.bar.com', 'bar');

        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array('email' => 'badfoo@api.bar.com', 'password' => 'barfoo'),
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(100, $data['code']);
    }

    public function testLoginOk()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), 'foo@api.bar.com', 'bar');

        $crawler = $client->request(
            'POST',
            '/api/v1/login',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array('email' => 'foo@api.bar.com', 'password' => 'bar'),
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('foo@api.bar.com', $data['email']);
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

        $crawler = $client->request(
            'POST',
            '/api/v1/referral',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array(
                    'email' => $userReferred->getEmail(),
                    'referral_code' => $user->getId(),
                ),
                'identity' => $cognitoIdentityId
            ))
        );
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

        $crawler = $client->request(
            'POST',
            '/api/v1/referral',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array(
                    'email' => 'foo',
                    'referral_code' => 'foo',
                ),
                'identity' => $cognitoIdentityId
            ))
        );
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

        $crawler = $client->request(
            'POST',
            '/api/v1/sns',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array(
                    // @codingStandardsIgnoreStart
                    'endpoint' => 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/344008b8-a266-3d7b-baa4-f1e8cf9fc16e'
                    // @codingStandardsIgnoreEnd
                ),
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
    }

    public function testSnsMissingEndpoint()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = $client->request(
            'POST',
            '/api/v1/sns',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array(),
                'identity' => $cognitoIdentityId
            ))
        );
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
        $user = static::createUser($this->getUserManager($client), 'token@api.bar.com', 'bar');

        $crawler = $client->request(
            'POST',
            '/api/v1/token',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array('token' => $user->getToken()),
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue(strlen($data['token']) > 20);
    }

    public function testTokenBad()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);
        $user = static::createUser($this->getUserManager($client), 'badtoken@api.bar.com', 'bar');

        $crawler = $client->request(
            'POST',
            '/api/v1/token',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array('token' => $user->getToken() + 'bad'),
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(403, $client->getResponse()->getStatusCode());
    }

    public function testTokenMissing()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = $client->request(
            'POST',
            '/api/v1/token',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => [],
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(400, $client->getResponse()->getStatusCode());
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

        $crawler = $client->request(
            'POST',
            '/api/v1/user',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array('email' => 'dup-user@api.bar.com'),
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(422, $client->getResponse()->getStatusCode());
    }

    public function testUserCreate()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = $client->request(
            'POST',
            '/api/v1/user',
            array(),
            array(),
            array('CONTENT_TYPE' => 'application/json'),
            json_encode(array(
                'body' => array('email' => 'api-new-user@api.bar.com'),
                'identity' => $cognitoIdentityId
            ))
        );
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals('api-new-user@api.bar.com', $data['email']);

        $dm = $this->getManager($client);
        $repo = $dm->getRepository(User::class);
        $fooUser = $repo->findOneBy(['email' => 'api-new-user@api.bar.com']);
        $this->assertTrue($fooUser !== null);
    }

    public function testUserCreateIp()
    {
        $client = static::createClient();
        $cognitoIdentityId = $this->getUnauthIdentity($client);

        $crawler = $client->request(
            'POST',
            '/api/v1/user',
            array(),
            array(),
            array(
                'CONTENT_TYPE' => 'application/json',
            ),
            json_encode(array(
                'body' => array('email' => 'api-ip-user@api.bar.com'),
                'identity' => $cognitoIdentityId
            ))
        );
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
    
    // helpers

    protected function getUnauthIdentity($client)
    {
        $cognitoIdentityId = $client->getContainer()->get('app.cognito.identity')->getId();
        //$cognitoIdentityId = 'eu-west-1:85376078-5f0f-43b8-8529-9021bb2096a4';
        // @codingStandardsIgnoreStart
        $identity = sprintf('{cognitoIdentityPoolId=eu-west-1:e7a6cfd2-c60f-4a04-a7a0-79eec2150720, accountId=812402538357, cognitoIdentityId=%s, caller=AROAIOCRWVZM5HTY5DI3E:CognitoIdentityCredentials, apiKey=null, sourceIp=62.253.24.189, cognitoAuthenticationType=unauthenticated, cognitoAuthenticationProvider=null, userArn=arn:aws:sts::812402538357:assumed-role/Cognito_sosureUnauth_Role/CognitoIdentityCredentials, userAgent=aws-sdk-iOS/2.3.5 iPhone-OS/9.2.1 en_GB, user=AROAIOCRWVZM5HTY5DI3E:CognitoIdentityCredentials}"', $cognitoIdentityId);
        // @codingStandardsIgnoreEnd

        return $identity;
    }
    
    protected function getManager($client)
    {
        return $client->getContainer()->get('doctrine_mongodb')->getManager();
    }

    protected function getUserManager($client)
    {
        return $client->getContainer()->get('fos_user.user_manager');
    }
}
