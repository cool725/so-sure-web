<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-net
 */
class ApiAuthControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    const VALID_IMEI = '356938035643809';
    const INVALID_IMEI = '356938035643808';
    const BLACKLISTED_IMEI = '352000067704506';

    protected static $testUser;
    protected static $testUser2;
    protected static $testUser3;
    protected static $client;
    protected static $userManager;
    protected static $dm;
    protected static $identity;
    protected static $jwt;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        self::$client = self::createClient();
        self::$identity = self::$client->getContainer()->get('app.cognito.identity');
        self::$dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        self::$userManager = self::$client->getContainer()->get('fos_user.user_manager');
        self::$jwt = self::$client->getContainer()->get('app.jwt');
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
        self::$testUser3 = self::createUser(
            self::$userManager,
            'foobar@auth-api.so-sure.com',
            'barfoo'
        );
    }

    // invitation/{id} cancel

    /**
     *
     */
    public function testInvitationCancel()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId, self::$testUser);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $policyData = json_decode(self::$client->getResponse()->getContent(), true);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-cancel', $this),
            'name' => 'invite cancel test',
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $invitationData = json_decode(self::$client->getResponse()->getContent(), true);

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'cancel']);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
    }

    // ping / auth

    /**
     *
     */
    public function testAuthRequiresIdentity()
    {
        $crawler = self::$client->request('POST', '/api/v1/auth/ping?_method=GET');
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    /**
     *
     */
    public function testGetAnonIsAnon()
    {
        $crawler = self::$client->request('GET', '/api/v1/ping');
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(0, $data['code']);
    }

    // policy

    /**
     *
     */
    public function testNewPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId, self::$testUser);
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);

        $this->assertTrue(strlen($data['id']) > 5);
        $this->assertTrue(in_array('A0001', $data['phone_policy']['phone']['devices']));

        // Now make sure that the policy shows up against the user
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', self::$testUser->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $userData = json_decode(self::$client->getResponse()->getContent(), true);
        $foundPolicy = false;
        foreach ($userData['policies'] as $policy) {
            if ($policy['id'] == $data['id']) {
                $foundPolicy = true;
            }
        }
        $this->assertTrue($foundPolicy);
    }

    public function testNewPolicyDuplicateImei()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId, self::$testUser);
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);

        $imei = $data['phone_policy']['imei'];

        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 64,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_POLICY_DUPLICATE_IMEI, $data['code']);
    }

    public function testNewPolicyRateLimited()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        for ($i = 1; $i <= RateLimitService::$maxRequests[RateLimitService::TYPE_POLICY] + 1; $i++) {
            $crawler = $this->createPolicy($cognitoIdentityId, self::$testUser, false);
        }
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, $data['code']);
    }

    public function testNewPolicyInvalidUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser2);
        $crawler = $this->createPolicy($cognitoIdentityId, null);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);

        $this->assertEquals(ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS, $data['code']);
    }

    public function testNewPolicyMemoryExceeded()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 512,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);

        $this->assertTrue(strlen($data['id']) > 5);
        $this->assertEquals('128', $data['phone_policy']['phone']['memory']);
    }

    public function testNewPolicyMemoryStandard()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 60,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $this->assertTrue(strlen($data['id']) > 5);
        $this->assertEquals('64', $data['phone_policy']['phone']['memory']);
    }

    public function testNewPolicyInvalidImei()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => self::INVALID_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::INVALID_IMEI]),
        ]]);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
    }

    public function testNewPolicyBlacklistedImei()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => self::BLACKLISTED_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::BLACKLISTED_IMEI]),
        ]]);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED, $data['code']);
    }

    public function testNewPolicyMissingData()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);

        // missing imei
        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::INVALID_IMEI]),
        ]]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        // missing memory
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        // missing device
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'memory' => 65,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        // missing make
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'device' => 'A0001',
            'memory' => 65,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());
    }

    public function testNewPolicyUnknownPhone()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);

        // missing imei
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'foo',
            'device' => 'bar',
            'memory' => 65,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $this->assertEquals(404, self::$client->getResponse()->getStatusCode());
    }

    // policy/{id}

    /**
     *
     */
    public function testGetPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId, self::$testUser);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $createData = json_decode(self::$client->getResponse()->getContent(), true);
        $policyId = $createData['id'];

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $getData = json_decode(self::$client->getResponse()->getContent(), true);

        $this->assertEquals($createData['id'], $getData['id']);
        $this->assertEquals($createData['phone_policy']['imei'], $getData['phone_policy']['imei']);
        $this->assertEquals(0, $createData['pot']['connections']);
        $this->assertEquals(7, $createData['pot']['max_connections']);
        $this->assertEquals(0, $createData['pot']['value']);
        $this->assertEquals(69.98, round($createData['pot']['max_value'], 2));
    }

    public function testGetPolicyUnknownId()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/policy/1?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(404, self::$client->getResponse()->getStatusCode());
    }

    public function testGetPolicyUnAuthUser()
    {
        $user = static::createUser(self::$userManager, 'getpolicy-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    // policy/{id}/dd

    /**
     *
     */
    public function testNewPolicyDdMissingData()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'sort_code' => '333333',
            'account_number' => '12345678',
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'account_number' => '12345678',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'sort_code' => '12345678',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'account_number' => '12345678',
            'sort_code' => '12345678',
            'first_name' => 'foo',
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'account_number' => '12345678',
            'sort_code' => '12345678',
            'last_name' => 'bar',
        ]);
        $this->assertEquals(400, self::$client->getResponse()->getStatusCode());
    }

    public function testNewPolicyDdUnknownPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'sort_code' => '200000',
            'account_number' => '12345678',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]);
        $this->assertEquals(404, self::$client->getResponse()->getStatusCode());
    }

    /**
     *
     */
    public function testNewPolicyDdOk()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        self::$testUser->setFirstName('foo');
        self::$testUser->setLastName('bar');
        self::$dm->flush();

        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'BX11LT',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $crawler = $this->createPolicy($cognitoIdentityId, self::$testUser);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);

        $url = sprintf("/api/v1/auth/policy/%s/dd", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'sort_code' => '200000',
            'account_number' => '55779911',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $policyData = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertTrue($policyData['status'] == null);
        $this->assertEquals($data['id'], $policyData['id']);
    }

    // policy/{id}/invitation

    /**
     *
     */
    public function testNewEmailAndDupInvitation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId, self::$testUser);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $data['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'patrick@so-sure.com',
            'name' => 'functional test',
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'patrick@so-sure.com',
            'name' => 'functional test',
        ]);
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
        $this->assertEquals(ApiErrorCode::ERROR_INVITATION_DUPLICATE, $data['code']);
    }

    /**
     *
     */
    public function testNewSmsAndDupInvitation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId, self::$testUser);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $data['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900000',
            'name' => 'functional test',
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900000',
            'name' => 'functional test',
        ]);
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
        $this->assertEquals(ApiErrorCode::ERROR_INVITATION_DUPLICATE, $data['code']);
    }

    public function testSentInvitationAppears()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->createPolicy($cognitoIdentityId, self::$testUser);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $data['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => $this->generateEmail('invite-appears', $this),
            'name' => 'Invitation Name',
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $policyData = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertTrue(count($policyData['sent_invitiations']) > 0);
        $foundInvitation = false;
        foreach ($policyData['sent_invitiations'] as $invitation) {
            if ($invitation['name'] == "Invitation Name") {
                $foundInvitation = true;
            }
        }
        $this->assertTrue($foundInvitation);
    }

    public function testReceivedInvitationAppears()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser2);
        $crawler = $this->createPolicy($cognitoIdentityId, self::$testUser2);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $data['id']);

        print sprintf("Invite from %s to %s", self::$testUser2->getName(), self::$testUser->getName());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::$testUser->getEmail(),
            'name' => self::$testUser->getName(),
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $invitationData = json_decode(self::$client->getResponse()->getContent(), true);

        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s?_method=GET&debug=true', self::$testUser->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $userData = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertTrue(count($userData['received_invitations']) > 0);
        $foundInvitation = false;
        foreach ($userData['received_invitations'] as $invitation) {
            if ($invitation['id'] == $invitationData['id']) {
                $foundInvitation = true;
                $this->assertEquals(self::$testUser2->getId(), $invitation['inviter_id']);
            }
        }
        $this->assertTrue($foundInvitation);
    }

    // secret

    /**
     *
     */
    public function testAuthSecret()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/secret?_method=GET', []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals('ThisTokenIsNotSoSecretChangeIt', $data['secret']);
    }

    // user

    /**
     *
     */
    public function testGetCurrentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $data['email']);
    }

    // user/{id}

    /**
     *
     */
    public function testGetUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', self::$testUser->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $data['email']);
    }

    public function testGetUserDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    public function testGetUnAuthUser()
    {
        $user = static::createUser(self::$userManager, 'getuser-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    // put user/{id}

    /**
     *
     */
    public function testUpdateUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'first_name' => 'bar',
            'last_name' => 'foo',
            'email' => 'barfoo@auth-api.so-sure.com',
            'mobile_number' => '+447700900000',
            'facebook_id' => 'abcd',
            'facebook_access_token' => 'zy',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $result = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals('barfoo@auth-api.so-sure.com', $result['email']);
        $this->assertEquals('bar', $result['first_name']);
        $this->assertEquals('foo', $result['last_name']);
        $this->assertEquals('+447700900000', $result['mobile_number']);
        $this->assertEquals('abcd', $result['facebook_id']);
    }

    public function testUpdateFacebook()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'first_name' => 'bar',
            'last_name' => 'foo',
            'email' => 'barfoo@auth-api.so-sure.com',
            'mobile_number' => '+447700900000',
            'facebook_id' => 'abcd',
            'facebook_access_token' => 'zy',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $result = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals('abcd', $result['facebook_id']);

        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, ['last_name' => 'barfoo']);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $result = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals('abcd', $result['facebook_id']);

        // facebook update needs auth token as well
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'facebook_id' => 'barfoo'
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $result = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals('abcd', $result['facebook_id']);

        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'facebook_id' => 'barfoo',
            'facebook_access_token' => 'lala'
        ]);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        $result = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals('barfoo', $result['facebook_id']);
    }

    public function testUpdateUserDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser2->getId());
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'first_name' => 'bar',
        ]);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    public function testUpdateUserInvalidEmail()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'email' => 'barfoo@',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $data['code']);
    }

    public function testUpdateUserInvalidMobile()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'email' => static::generateEmail('invalid-mobile', $this),
            'mobile_number' => '+44770090000',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $data['code']);
    }

    // user/{id}/address

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
            'postcode' => 'BX11LT',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->assertEquals(200, self::$client->getResponse()->getStatusCode());

        $result = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $result['email']);
        $this->assertTrue(isset($result['addresses']));
        $this->assertTrue(isset($result['addresses'][0]));
        $this->assertEquals($data['type'], $result['addresses'][0]['type']);
        $this->assertEquals($data['line1'], $result['addresses'][0]['line1']);
        $this->assertEquals($data['city'], $result['addresses'][0]['city']);
        $this->assertEquals($data['postcode'], $result['addresses'][0]['postcode']);
    }

    public function testUserAddAddressDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'BX11LT',
        ]);
        $this->assertEquals(403, self::$client->getResponse()->getStatusCode());
    }

    public function testUserInvalidAddress()
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
        $this->assertEquals(422, self::$client->getResponse()->getStatusCode());

        $result = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals(ApiErrorCode::ERROR_USER_INVALID_ADDRESS, $result['code']);
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

    protected function getValidationData($cognitoIdentityId, $validateData)
    {
        return static::$jwt->create(
            $cognitoIdentityId,
            $validateData
        );
    }

    protected function createPolicy($cognitoIdentityId, $user, $clearRateLimit = true)
    {
        if ($user) {
            $userUpdateUrl = sprintf('/api/v1/auth/user/%s', $user->getId());
            static::putRequest(self::$client, $cognitoIdentityId, $userUpdateUrl, [
                'first_name' => 'foo',
                'last_name' => 'bar',
                'mobile_number' => '+447700900000',
            ]);

            $url = sprintf('/api/v1/auth/user/%s/address', $user->getId());
            $data = [
                'type' => 'billing',
                'line1' => 'address line 1',
                'city' => 'London',
                'postcode' => 'BX11LT',
            ];
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
            $this->assertEquals(200, self::$client->getResponse()->getStatusCode());
        }

        if ($clearRateLimit) {
            $this->clearRateLimit();
        }
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);

        return $crawler;
    }

    protected function clearRateLimit()
    {
        // clear out redis rate limiting
        self::$client->getContainer()->get('snc_redis.default')->flushdb();
    }
}
