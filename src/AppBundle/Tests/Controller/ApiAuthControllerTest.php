<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

/**
 * @group functional-net
 */
class ApiAuthControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    const VALID_IMEI = '356938035643809';
    const INVALID_IMEI = '356938035643808';

    protected static $testUser;
    protected static $testUser2;
    protected static $client;
    protected static $userManager;
    protected static $identity;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        self::$client = self::createClient();
        self::$identity = self::$client->getContainer()->get('app.cognito.identity');
        self::$userManager = self::$client->getContainer()->get('fos_user.user_manager');
        self::$testUser = self::createUser(
            self::$userManager,
            'foo@auth-api.so-sure.com',
            'foo'
        );
        self::$testUser2 = self::createUser(
            self::$userManager,
            'bar@auth-api.so-sure.com',
            'bar'
        );
    }

    // auth

    /**
     *
     */
    public function testAuthRequiresIdentity()
    {
        $crawler = self::$client->request('POST', '/api/v1/auth/ping');
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    /**
     *
     */
    public function testGetIsAnon()
    {
        $crawler = self::$client->request('GET', '/api/v1/auth/ping');
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
    }

    // policy

    /**
     *
     */
    public function testNewPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'user_id' => self::$testUser->getId(),
            'imei' => self::VALID_IMEI,
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
    }

    public function testNewPolicyInvalidImei()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'user_id' => self::$testUser->getId(),
            'imei' => self::INVALID_IMEI,
        ]);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
    }

    public function testNewPolicyDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'user_id' => self::$testUser2->getId(),
            'imei' => self::VALID_IMEI,
        ]);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    public function testNewPolicyUnknownUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'user_id' => '123',
            'imei' => self::VALID_IMEI,
        ]);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
    }

    public function testNewPolicyMissingData()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'user_id' => self::$testUser->getId(),
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'imei' => self::VALID_IMEI,
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());
    }

    // policy/{id}

    /**
     *
     */
    public function testGetPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'user_id' => self::$testUser->getId(),
            'imei' => self::VALID_IMEI,
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $createData = json_decode(self::$client->getResponse()->getContent(), true);
        $policyId = $createData['id'];

        $url = sprintf('/api/v1/auth/policy/%s', $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $getData = json_decode(self::$client->getResponse()->getContent(), true);

        $this->assertEquals($createData['id'], $getData['id']);
        $this->assertEquals($createData['imei'], $getData['imei']);
        $this->assertEquals($createData['user']['id'], $getData['user']['id']);
    }

    public function testGetPolicyUnknownId()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/policy/1');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(404, self::$client->getResponse()->getStatusCode());
    }

    // user/{id}

    /**
     *
     */
    public function testGetUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $data['email']);
    }

    public function testGetUserDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    public function testGetUnAuthUser()
    {
        $user = static::createUser(self::$userManager, 'getuser-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    // user/{id}

    /**
     *
     */
    public function testUserAddAddress()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'ec1v 1rx',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $result = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $result['email']);
        $this->assertEquals($data['type'], $result['address'][0]['type']);
        $this->assertEquals($data['line1'], $result['address'][0]['line1']);
        $this->assertEquals($data['city'], $result['address'][0]['city']);
        $this->assertEquals($data['postcode'], $result['address'][0]['postcode']);
    }

    public function testUserAddAddressDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'ec1v 1rx',
        ]);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    // helpers

    /**
     *
     */
    protected function getAuthUser($user)
    {
        return static::authUser(self::$identity, $user);
    }

    protected function getUnauthIdentity()
    {
        return static::getIdentityString(self::$identity->getId());
    }
}
