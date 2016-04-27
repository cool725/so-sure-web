<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use AppBundle\Document\Invitation\EmailInvitation;

/**
 * @group functional-net
 */
class ApiAuthControllerTest extends BaseControllerTest
{
    const VALID_IMEI = '356938035643809';
    const INVALID_IMEI = '356938035643808';
    const BLACKLISTED_IMEI = '352000067704506';

    protected static $testUser;
    protected static $testUser2;
    protected static $testUser3;
    protected static $testUserDisabled;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
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

    // address

    /**
     *
     */
    public function testAddress()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/address?postcode=BX11LT&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals("so-sure Test Address Line 1", $data['line1']);
        $this->assertEquals("so-sure Test Address Line 2", $data['line2']);
        $this->assertEquals("so-sure Test City", $data['city']);
        $this->assertEquals("BX1 1LT", $data['postcode']);
    }

    public function testAddressRateLimited()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/address?postcode=BX11LT&_method=GET';

        // Run enough to trigger cognito rate limit
        for ($i = 0; $i < 4; $i++) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        }

        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_TOO_MANY_REQUESTS);
    }

    /* TODO: Consider moving to a different type of test.
     * Note that once we're out of test mode mid-apr 2016,
     * then it should be possible to use this test
    public function testAddress()
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/api/v1/auth/address?postcode=WR53DA');
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals(200, $client->getResponse()->getStatusCode(), print_r($data, true));
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
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/address?postcode=&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400);
    }

    // invitation/{id}

    /**
     *
     */
    public function testInvitationCancel()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-cancel', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($cognitoIdentityId, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-cancel', $this),
            'name' => 'invite cancel test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'cancel']);
        $data = $this->verifyResponse(200);
    }

    public function testInvitationUnknown()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-unknown', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($cognitoIdentityId, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-unknown', $this),
            'name' => 'invite unknown action test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'foo']);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testInvitationMissingActionAction()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-missing', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($cognitoIdentityId, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-missing', $this),
            'name' => 'invite missing action test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testInvitationReinviteAction()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-reinvite', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($cognitoIdentityId, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-reinvite', $this),
            'name' => 'invite revite test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $emailRepo = self::$dm->getRepository(EmailInvitation::class);
        $invitation = $emailRepo->find($invitationData['id']);
        $this->assertFalse($invitation->canReinvite());

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'reinvite']);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_LIMIT);
    }

    // ping / auth

    /**
     *
     */
    public function testAuthRequiresIdentity()
    {
        $crawler = self::$client->request('POST', '/api/v1/auth/ping?_method=GET');
        $data = $this->verifyResponse(403);
    }

    /**
     *
     */
    public function testGetAnonIsAnon()
    {
        $crawler = self::$client->request('GET', '/api/v1/ping');
        $data = $this->verifyResponse(200, 0);
    }

    // policy

    /**
     *
     */
    public function testNewPolicy()
    {
        $this->clearRateLimit();
        $user = self::createUser(self::$userManager, self::generateEmail('policy', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'HTC',
            'device' => 'A0001',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);

        $data = $this->verifyResponse(200);

        $this->assertTrue(strlen($data['id']) > 5);
        $this->assertTrue(in_array('A0001', $data['phone_policy']['phone']['devices']));
        $this->assertGreaterThan(0, $data['monthly_premium']);

        // Now make sure that the policy shows up against the user
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $userData = $this->verifyResponse(200);

        $foundPolicy = false;
        foreach ($userData['policies'] as $policy) {
            if ($policy['id'] == $data['id']) {
                $foundPolicy = true;
            }
        }
        $this->assertTrue($foundPolicy);
    }

    public function testNewPolicyDisabledUser()
    {
        $this->clearRateLimit();
        $userDisabled = self::createUser(self::$userManager, self::generateEmail('disabled', $this), 'foo');
        $cognitoIdentityId = $this->getAuthUser($userDisabled);
        $userDisabled->setEnabled(false);
        self::$dm->flush();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(403);
    }

    public function testNewPolicyExpiredUser()
    {
        $this->clearRateLimit();
        $userExpired = self::createUser(self::$userManager, self::generateEmail('expired', $this), 'foo');
        $cognitoIdentityId = $this->getAuthUser($userExpired);
        $userExpired->setExpired(true);
        self::$dm->flush();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(403);
    }

    public function testNewPolicyLockedUser()
    {
        $this->clearRateLimit();
        $userLocked = self::createUser(self::$userManager, self::generateEmail('locked', $this), 'foo');
        $cognitoIdentityId = $this->getAuthUser($userLocked);
        $userLocked->setLocked(true);
        self::$dm->flush();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(403);
    }

    public function testNewPolicyDuplicateImei()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->generatePolicy($cognitoIdentityId, self::$testUser);
        $data = $this->verifyResponse(200);

        $imei = $data['phone_policy']['imei'];

        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 6',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_DUPLICATE_IMEI);
    }

    public function testNewPolicyRateLimited()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $limit = RateLimitService::$maxRequests[RateLimitService::DEVICE_TYPE_POLICY]  *
            RateLimitService::IP_ADDRESS_MULTIPLIER;
        for ($i = 1; $i <= $limit + 1; $i++) {
            $user = self::createUser(
                self::$userManager,
                self::generateEmail('rate-limit-email-' + $i, $this),
                'foo'
            );
            $cognitoIdentityId = $this->getAuthUser($user);
            $this->updateUserDetails($cognitoIdentityId, $user);
            $imei = self::generateRandomImei();
            $crawler = static::postRequest(
                self::$client,
                $cognitoIdentityId,
                '/api/v1/auth/policy',
                ['phone_policy' => [
                    'imei' => $imei,
                    'make' => 'OnePlus',
                    'device' => 'A0001',
                    'memory' => 65,
                    'rooted' => false,
                    'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
                ]]
            );
        }
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_TOO_MANY_REQUESTS);
    }

    public function testNewPolicyInvalidUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser2);
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS);
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
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(200);

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
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(200);

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
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::INVALID_IMEI]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_INVALID);
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
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::BLACKLISTED_IMEI]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED);
    }

    public function testNewPolicyRooted()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'rooted' => true,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED);
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
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::INVALID_IMEI]),
        ]]);
        $data = $this->verifyResponse(400);

        // missing memory
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(400);

        // missing device
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'memory' => 65,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(400);

        // missing make
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'device' => 'A0001',
            'memory' => 65,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(400);

        // missing rooted
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 65,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(400);
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
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(404);
    }

    // policy/{id}

    /**
     *
     */
    public function testGetNullPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->generatePolicy($cognitoIdentityId, self::$testUser);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);

        $this->assertEquals($createData['id'], $getData['id']);
        $this->assertEquals($createData['phone_policy']['imei'], $getData['phone_policy']['imei']);
        $this->assertEquals(0, $createData['pot']['connections']);
        $this->assertEquals(0, $createData['pot']['max_connections']);
        $this->assertEquals(0, $createData['pot']['value']);
        $this->assertEquals(0, round($createData['pot']['max_value'], 2));
    }

    public function testGetPolicyUnknownId()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/policy/1?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(404);
    }

    public function testGetPolicyUnAuthUser()
    {
        $user = static::createUser(self::$userManager, 'getpolicy-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(403);
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
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'account_number' => '12345678',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'sort_code' => '12345678',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'account_number' => '12345678',
            'sort_code' => '12345678',
            'first_name' => 'foo',
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/dd', [
            'account_number' => '12345678',
            'sort_code' => '12345678',
            'last_name' => 'bar',
        ]);
        $data = $this->verifyResponse(400);
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
        $data = $this->verifyResponse(404);
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
        $data = $this->verifyResponse(200);

        $crawler = $this->generatePolicy($cognitoIdentityId, self::$testUser);
        $data = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/policy/%s/dd", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'sort_code' => '200000',
            'account_number' => '55779911',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(PhonePolicy::STATUS_PENDING, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);
    }

    // policy/{id}/invitation

    /**
     *
     */
    public function testNewEmailAndDupInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-email', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($cognitoIdentityId, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'patrick@so-sure.com',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(200);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'patrick@so-sure.com',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_DUPLICATE);
    }

    /**
     *
     */
    public function testNewSmsAndDupInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-sms', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($cognitoIdentityId, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900002',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(200);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900002',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_DUPLICATE);
    }

    public function testSentInvitationAppears()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('sent-invitation', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($cognitoIdentityId, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => $this->generateEmail('invite-appears', $this),
            'name' => 'Invitation Name',
        ]);
        $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $policyData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $policyData = $this->verifyResponse(200);
        $this->assertTrue(count($policyData['sent_invitations']) > 0);
        $foundInvitation = false;
        foreach ($policyData['sent_invitations'] as $invitation) {
            if ($invitation['name'] == "Invitation Name") {
                $foundInvitation = true;
            }
        }
        $this->assertTrue($foundInvitation);
    }

    public function testReceivedInvitationAppears()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser2);
        $crawler = $this->generatePolicy($cognitoIdentityId, self::$testUser2);
        $data = $this->verifyResponse(200);

        $this->payPolicy($cognitoIdentityId, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $data['id']);

        //print sprintf("Invite from %s to %s", self::$testUser2->getName(), self::$testUser->getName());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::$testUser->getEmail(),
            'name' => self::$testUser->getName(),
        ]);
        $invitationData = $this->verifyResponse(200);

        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s?_method=GET&debug=true', self::$testUser->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $userData = $this->verifyResponse(200);
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

    public function testUnableToInviteSelf()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-notself', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($cognitoIdentityId, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invitation-notself', $this),
            'name' => 'Invitation Name',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_SELF_INVITATION);
    }

    // policy/{id}/terms

    /**
     *
     */
    public function testGetPolicyTerms()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-terms', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $url = sprintf('/api/v1/auth/policy/%s/terms?_method=GET', $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);
        $policyUrl = self::$router->generate('policy_terms', ['id' => $policyId]);
        //print $getData["view_url"];
        $this->assertTrue(stripos($getData["view_url"], $policyUrl) >= 0);
        $this->assertTrue(stripos($getData["view_url"], 'http') >= 0);
    }

    // secret

    /**
     *
     */
    public function testAuthSecret()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/secret?_method=GET', []);
        $data = $this->verifyResponse(200);
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
        $data = $this->verifyResponse(200);
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
        $data = $this->verifyResponse(200);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $data['email']);
    }

    public function testGetUserDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $result = $this->verifyResponse(403);
    }

    public function testGetUnAuthUser()
    {
        $user = static::createUser(self::$userManager, 'getuser-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $result = $this->verifyResponse(403);
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
        $result = $this->verifyResponse(200);
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
        $result = $this->verifyResponse(200);
        $this->assertEquals('abcd', $result['facebook_id']);

        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, ['last_name' => 'barfoo']);
        $result = $this->verifyResponse(200);
        $this->assertEquals('abcd', $result['facebook_id']);

        // facebook update needs auth token as well
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'facebook_id' => 'barfoo'
        ]);
        $result = $this->verifyResponse(200);
        $this->assertEquals('abcd', $result['facebook_id']);

        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'facebook_id' => 'barfoo',
            'facebook_access_token' => 'lala'
        ]);
        $result = $this->verifyResponse(200);
        $this->assertEquals('barfoo', $result['facebook_id']);
    }

    public function testUpdateUserDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser2->getId());
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'first_name' => 'bar',
        ]);
        $data = $this->verifyResponse(403);
    }

    public function testUpdateUserInvalidEmail()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'email' => 'barfoo@',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
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
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
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
        $result = $this->verifyResponse(200);
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
        $data = $this->verifyResponse(403);
    }

    public function testUserInvalidAddress()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'ZZ99 3CZ',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_INVALID_ADDRESS);
    }

    // helpers

    /**
     *
     */
    protected function generatePolicy($cognitoIdentityId, $user, $clearRateLimit = true)
    {
        if ($user) {
            $this->updateUserDetails($cognitoIdentityId, $user);
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
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $this->verifyResponse(200);

        return $crawler;
    }

    protected function updateUserDetails($cognitoIdentityId, $user)
    {
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
        $this->verifyResponse(200);
    }

    protected function payPolicy($cognitoIdentityId, $policyId)
    {
        $url = sprintf("/api/v1/auth/policy/%s/dd", $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'sort_code' => '200000',
            'account_number' => '55779911',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(PhonePolicy::STATUS_PENDING, $policyData['status']);
        $this->assertEquals($policyId, $policyData['id']);
    }
}
