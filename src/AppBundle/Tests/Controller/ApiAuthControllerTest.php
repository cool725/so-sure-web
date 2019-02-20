<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\DateTrait;
use AppBundle\Document\IdentityLog;
use AppBundle\Repository\Invitation\EmailInvitationRepository;
use AppBundle\Service\JudopayService;
use AppBundle\Service\PCAService;
use AppBundle\Service\PolicyService;
use AppBundle\Tests\UserClassTrait;
use FacebookAds\Api;
use PhpParser\Node\Expr\AssignOp\Mul;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Charge;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\LostPhone;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\SCode;
use AppBundle\Document\Reward;
use AppBundle\Document\MultiPay;
use AppBundle\Document\PhonePrice;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Listener\UserListener;
use AppBundle\Service\RateLimitService;
use AppBundle\Service\ReceperioService;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Classes\SoSure;
use AppBundle\Document\Payment\PolicyDiscountPayment;
use Symfony\Component\HttpKernel\DataCollector\EventDataCollector;
use Predis\Client;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\ApiAuthControllerTest
 */
class ApiAuthControllerTest extends BaseApiControllerTest
{
    use UserClassTrait;
    use DateTrait;

    const VALID_IMEI = '356938035643809';
    const INVALID_IMEI = '356938035643808';
    const BLACKLISTED_IMEI = '352000067704506';
    const LOSTSTOLEN_IMEI = '351451208401216';

    /** @var User */
    protected static $testUser;
    protected static $testUser2;
    protected static $testUser3;
    protected static $testUserDisabled;
    protected static $reward;
    protected static $expiredPhone;

    public function tearDown()
    {
    }

    public static function createTestUsers()
    {
        /** @var User $testUser */
        $testUser = self::createUser(
            self::$userManager,
            'foo@auth-api.so-sure.com',
            'foo'
        );
        self::$testUser = $testUser;
        self::$testUser->setFirstName('Foo');
        self::$testUser->setLastName('Bar');
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

        $user = self::createUser(
            self::$userManager,
            'bonus@so-sure.net',
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
    }

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        self::createTestUsers();
        $phoneRepo = static::$dm->getRepository(Phone::class);
        self::$expiredPhone = $phoneRepo->findOneBy(['make' => 'Apple', 'model' => 'iPhone 4']);

        self::populateYearlyPostcodes();
        static::$dm->flush();
    }

    public function setUp()
    {
        parent::setUp();
        $this->clearRateLimit();
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

    public function testLookupAddress()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/lookup/address?postcode=BX11LT&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals("so-sure Test Address Line 1", $data['line1']);
        $this->assertEquals("so-sure Test Address Line 2", $data['line2']);
        $this->assertEquals("so-sure Test City", $data['city']);
        $this->assertEquals("BX1 1LT", $data['postcode']);
    }

    public function testAddressValidation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/address?postcode[$ne]=BX11LT&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testLookupAddressValidation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/lookup/address?postcode[$ne]=BX11LT&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testAddressQuote()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf(
            '/api/v1/auth/address?postcode=%s&number=%s&_method=GET',
            urlencode('RG47RG'),
            urlencode("flat 6, 17 st peter's court")
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        /* actual data
        $this->assertEquals("Flat 6", $data['line1']);
        $this->assertEquals("RG4 7RG", $data['postcode']);
        */
        $this->assertEquals("Lock View", $data['line1']);
        $this->assertEquals("WR5 3DA", $data['postcode']);
    }

    public function testLookupAddressQuote()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf(
            '/api/v1/auth/lookup/address?postcode=%s&number=%s&_method=GET',
            urlencode('RG47RG'),
            urlencode("flat 6, 17 st peter's court")
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        /* actual data
        $this->assertEquals("Flat 6", $data['line1']);
        $this->assertEquals("RG4 7RG", $data['postcode']);
        */
        $this->assertEquals("Lock View", $data['line1']);
        $this->assertEquals("WR5 3DA", $data['postcode']);
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

    public function testLookupAddressRateLimited()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/lookup/address?postcode=BX11LT&_method=GET';

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
        $this->assertEquals(200, $client->getResponse()->getStatusCode(), json_encode($data));
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

    public function testLookupAddressReqParam()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/lookup/address?postcode=&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400);
    }

    // GET /lookup/bacs

    /**
     *
     */
    public function testLookupBacs()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf(
            '/api/v1/auth/lookup/bacs?sort_code=%s&account_number=%s&_method=GET',
            PCAService::TEST_SORT_CODE,
            PCAService::TEST_ACCOUNT_NUMBER_OK
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);

        $this->assertEquals('foo bank', $data['bank_name']);
        $this->assertEquals('Foo Bar', $data['account_name']);
        $this->assertEquals('00-00-99', $data['displayable_sort_code']);
        $this->assertEquals('XXXX4321', $data['displayable_account_number']);
        $this->assertEquals('BX1 1LT', $data['bank_address']['postcode']);
        $this->assertEquals('pending-init', $data['mandate_status']);
        $this->assertEquals('', $data['mandate']);
        $this->assertGreaterThan(8, mb_strlen($data['initial_notification_date']));
        $this->assertEquals('', $data['standard_notification_day']);
    }

    public function testLookupBacsWithMandate()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf(
            '/api/v1/auth/lookup/bacs?sort_code=%s&account_number=%s&include=mandate&_method=GET',
            PCAService::TEST_SORT_CODE,
            PCAService::TEST_ACCOUNT_NUMBER_OK
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);

        $this->assertEquals('foo bank', $data['bank_name']);
        $this->assertEquals('Foo Bar', $data['account_name']);
        $this->assertEquals('00-00-99', $data['displayable_sort_code']);
        $this->assertEquals('XXXX4321', $data['displayable_account_number']);
        $this->assertEquals('XXXX4321', $data['displayable_account_number']);
        $this->assertEquals('BX1 1LT', $data['bank_address']['postcode']);
        $this->assertEquals('pending-init', $data['mandate_status']);
        $this->assertGreaterThan(5, mb_strlen($data['mandate']));
        $this->assertGreaterThan(8, mb_strlen($data['initial_notification_date']));
        $this->assertEquals('', $data['standard_notification_day']);
    }

    public function testLookupBacsInvalidSortCode()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf(
            '/api/v1/auth/lookup/bacs?sort_code=%s&account_number=%s&include=mandate&_method=GET',
            PCAService::TEST_SORT_CODE,
            PCAService::TEST_ACCOUNT_NUMBER_INVALID_SORT_CODE
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_INVALID_SORTCODE);
    }

    public function testLookupBacsInvalidAccountNumber()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf(
            '/api/v1/auth/lookup/bacs?sort_code=%s&account_number=%s&include=mandate&_method=GET',
            PCAService::TEST_SORT_CODE,
            PCAService::TEST_ACCOUNT_NUMBER_INVALID_ACCOUNT_NUMBER
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_INVALID_NUMBER);
    }

    public function testLookupBacsNoDD()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf(
            '/api/v1/auth/lookup/bacs?sort_code=%s&account_number=%s&include=mandate&_method=GET',
            PCAService::TEST_SORT_CODE,
            PCAService::TEST_ACCOUNT_NUMBER_NO_DD
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_DIRECT_DEBIT_UNAVAILABLE);
    }

    public function testLookupBacsReqParam()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = '/api/v1/auth/lookup/bacs?sort_code=&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400);

        $url = '/api/v1/auth/lookup/bacs?account_number=&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400);

        $url = '/api/v1/auth/lookup/bacs?sort_code=&account_number=&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400);
    }

    // POST /detected-imei

    /**
     *
     */
    public function testDetectedImeiMissing()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testDetectedImeiMissing', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $url = "/api/v1/auth/detected-imei";
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
        ]);
        $this->verifyResponse(400);

        $url = "/api/v1/auth/detected-imei";
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            //'detected_imei' => 'foo',
            'bucket' => 'foo',
            'key' => 'foo',
        ]);
        $this->verifyResponse(400);

        $url = "/api/v1/auth/detected-imei";
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'detected_imei' => 'foo',
            //'bucket' => 'foo',
            'key' => 'foo',
        ]);
        $this->verifyResponse(400);

        $url = "/api/v1/auth/detected-imei";
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'detected_imei' => 'foo',
            'bucket' => 'foo',
            //'key' => 'foo',
        ]);
        $this->verifyResponse(400);
    }

    public function testDetectedImeiWait()
    {
        $this->clearRateLimit();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testDetectedImeiWait', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $url = "/api/v1/auth/detected-imei";
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'detected_imei' => 'foo',
            'bucket' => 'foo',
            'key' => 'foo',
        ]);
        $this->verifyResponse(422, ApiErrorCode::ERROR_DETECTED_IMEI_MANUAL_PROCESSING);
    }

    public function testDetectedImeiSuggested()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testDetectedImeiSuggested', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $url = "/api/v1/auth/detected-imei";
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'detected_imei' => 'foo',
            'suggested_imei' => $policyData['phone_policy']['imei'],
            'bucket' => 'foo',
            'key' => 'foo',
        ]);
        $policy = $this->verifyResponse(200);
        $this->assertEquals('foo', $policy['phone_policy']['detected_imei']);
    }

    public function testDetectedImeiNonUser()
    {
        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('testDetectedImeiNonUserA', $this),
            'foo'
        );
        $userB = self::createUser(
            self::$userManager,
            self::generateEmail('testDetectedImeiNonUserB', $this),
            'foo'
        );
        $cognitoIdentityIdA = $this->getAuthUser($userA);
        $crawler = $this->generatePolicy($cognitoIdentityIdA, $userA);
        $policyData = $this->verifyResponse(200);

        $cognitoIdentityIdB = $this->getAuthUser($userB);
        $url = "/api/v1/auth/detected-imei";
        $crawler = static::postRequest(self::$client, $cognitoIdentityIdB, $url, [
            'detected_imei' => 'foo',
            'suggested_imei' => $policyData['phone_policy']['imei'],
            'bucket' => 'foo',
            'key' => 'foo',
        ]);
        $policy = $this->verifyResponse(403);
    }

    public function testDetectedImeiExpired()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testDetectedImeiExpired', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyDataA = $this->verifyResponse(200);
        $this->payPolicy($user, $policyDataA['id']);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyDataB = $this->verifyResponse(200);
        $this->payPolicy($user, $policyDataB['id']);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $policyA */
        $policyA = $repo->find($policyDataA['id']);
        $policyA->setImei($policyDataB['phone_policy']['imei']);
        $policyA->setStatus(Policy::STATUS_EXPIRED);
        /** @var PhonePolicy $policyB */
        $policyB = $repo->find($policyDataB['id']);
        $policyB->setStatus(Policy::STATUS_UNPAID);
        $dm->flush();

        $url = "/api/v1/auth/detected-imei";
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'detected_imei' => 'foo',
            'suggested_imei' => $policyDataB['phone_policy']['imei'],
            'bucket' => 'foo',
            'key' => 'foo',
        ]);
        $policy = $this->verifyResponse(200);
        $this->assertEquals('foo', $policy['phone_policy']['detected_imei']);
        $this->assertEquals($policyDataB['id'], $policy['id']);
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

        $this->payPolicy($user, $policyData['id']);
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

        $this->payPolicy($user, $policyData['id']);
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

        $this->payPolicy($user, $policyData['id']);
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

    public function testInvitationReinviteLimitedAction()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-reinvite-limited', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-reinvite-limited', $this),
            'name' => 'invite revite test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $emailRepo = self::$dm->getRepository(EmailInvitation::class);
        /** @var EmailInvitation $invitation */
        $invitation = $emailRepo->find($invitationData['id']);
        $this->assertFalse($invitation->canReinvite());

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'reinvite']);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_LIMIT);
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

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-reinvite', $this),
            'name' => 'invite revite test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $emailRepo = self::getDocumentManager(true)->getRepository(EmailInvitation::class);
        /** @var EmailInvitation $invitation */
        $invitation = $emailRepo->find($invitationData['id']);
        $invitation->setNextReinvited(new \DateTime('2016-01-01'));
        $this->getDocumentManager(true)->flush();
        $this->assertTrue($invitation->canReinvite());

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'reinvite']);
        $data = $this->verifyResponse(200);
    }

    public function testInvitationReinviteFullPotAction()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-reinvite-maxpot', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-reinvite-maxpot', $this),
            'name' => 'invite revite test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $emailRepo = self::getDocumentManager(true)->getRepository(EmailInvitation::class);
        /** @var EmailInvitation $invitation */
        $invitation = $emailRepo->find($invitationData['id']);
        $invitation->setNextReinvited(new \DateTime('2016-01-01'));

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::getContainer(true), $policyData['id']);
        $this->assertNotNull($updatedPolicy);
        $updatedPolicy->setPotValue($updatedPolicy->getMaxPot());
        $this->getDocumentManager(true)->flush();
        $this->assertTrue($invitation->canReinvite());

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'reinvite']);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_MAXPOT);
    }

    public function testInvitationReinviteClaimsAction()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-reinvite-claims', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invite-reinvite-claims', $this),
            'name' => 'invite revite test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $emailRepo = self::getDocumentManager(true)->getRepository(EmailInvitation::class);
        /** @var EmailInvitation $invitation */
        $invitation = $emailRepo->find($invitationData['id']);
        $invitation->setNextReinvited(new \DateTime('2016-01-01'));

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::getContainer(true), $policyData['id']);
        $this->assertNotNull($updatedPolicy);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $updatedPolicy->addClaim($claim);
        $this->getDocumentManager(true)->flush();
        $this->assertTrue($invitation->canReinvite());

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['action' => 'reinvite']);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_POLICY_HAS_CLAIM);
    }

    public function testInvitationAccept()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-accept-invitee', $this),
            'foo'
        );
        $inviteeCognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($inviteeCognitoIdentityId, $user);
        $inviteePolicyData = $this->verifyResponse(200);
        $this->payPolicy($user, $inviteePolicyData['id']);

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-accept', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('user-accept-invitee', $this),
            'name' => 'invite accept test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $inviteeCognitoIdentityId, $url, [
            'action' => 'accept',
            'policy_id' => $inviteePolicyData['id']
        ]);
        $data = $this->verifyResponse(200);

        // replay should fail
        $crawler = static::postRequest(self::$client, $inviteeCognitoIdentityId, $url, [
            'action' => 'accept',
            'policy_id' => $inviteePolicyData['id']
        ]);
        $data = $this->verifyResponse(500);
    }

    public function testInvitationValidation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-validation-invitee', $this),
            'foo'
        );
        $inviteeCognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($inviteeCognitoIdentityId, $user);
        $inviteePolicyData = $this->verifyResponse(200);
        $this->payPolicy($user, $inviteePolicyData['id']);

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-validation', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('user-claimaccept-invitee', $this),
            'name' => ['$ne' => 'invite claimaccept test'],
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testInvitationAcceptWithClaims()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-claimaccept-invitee', $this),
            'foo'
        );
        $inviteeCognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($inviteeCognitoIdentityId, $user);
        $inviteePolicyData = $this->verifyResponse(200);
        $this->payPolicy($user, $inviteePolicyData['id']);

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-claimaccept', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('user-claimaccept-invitee', $this),
            'name' => 'invite claimaccept test',
        ]);
        $invitationData = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::getContainer(true), $policyData['id']);
        $this->assertNotNull($updatedPolicy);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $updatedPolicy->addClaim($claim);
        $this->getDocumentManager(true)->flush();

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $inviteeCognitoIdentityId, $url, [
            'action' => 'accept',
            'policy_id' => $inviteePolicyData['id']
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_POLICY_HAS_CLAIM);
    }

    public function testInvitationAcceptWithPotFull()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-fullpotaccept-invitee', $this),
            'foo'
        );
        $inviteeCognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($inviteeCognitoIdentityId, $user);
        $inviteePolicyData = $this->verifyResponse(200);
        $this->payPolicy($user, $inviteePolicyData['id']);

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-fullpotaccept', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('user-fullpotaccept-invitee', $this),
            'name' => 'invite fullpotaccept test',
        ]);
        $invitationData = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::getContainer(true), $policyData['id']);
        $this->assertNotNull($updatedPolicy);
        $updatedPolicy->setPotValue($updatedPolicy->getMaxPot());
        $this->getDocumentManager(true)->flush();

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $inviteeCognitoIdentityId, $url, [
            'action' => 'accept',
            'policy_id' => $inviteePolicyData['id']
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_MAXPOT);
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
    public function testApiNewPolicy()
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
            'name' => "Kelvin’|s iPhone5",
        ]]);

        $data = $this->verifyResponse(200);
        $repo = self::$dm->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $repo->find($data['id']);
        $this->assertTrue($policy != null);
        $this->assertEquals('62.253.24.189', $policy->getIdentityLog()->getIp());
        $this->assertEquals('GB', $policy->getIdentityLog()->getCountry());
        $this->assertEquals([-0.13,51.5], $policy->getIdentityLog()->getLoc()->getCoordinates());

        $this->assertTrue(mb_strlen($data['id']) > 5);
        $this->assertTrue(in_array('A0001', $data['phone_policy']['phone']['devices']));
        $this->assertGreaterThan(0, $data['monthly_premium']);
        $this->assertGreaterThan(0, $data['yearly_premium']);
        $this->assertEquals($data['monthly_premium'], $data['adjusted_monthly_premium']);
        $this->assertEquals($data['yearly_premium'], $data['adjusted_yearly_premium']);
        $this->assertEquals("Kelvin’s iPhone5", $data['phone_policy']['name']);
        $this->assertNull($data['phone_policy']['picsure_status']);
        $this->assertTrue(count($data['phone_policy']['excesses']) > 0);
        foreach ($data['phone_policy']['excesses'] as $excess) {
            $excessMax = $policy->getPolicyTerms()->isPicSureEnabled() ? 150 : 70;
            $excessMin = $policy->getPolicyTerms()->isPicSureEnabled() ? 150 : 50;
            $this->assertGreaterThanOrEqual($excessMin, $excess['amount']);
            $this->assertLessThanOrEqual($excessMax, $excess['amount']);
        }

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

    public function testApiNewPolicyWithPolicyDiscount()
    {
        $this->clearRateLimit();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testApiNewPolicyWithPolicyDiscount', $this),
            'foo',
            true
        );
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
            'name' => "Kelvin’|s iPhone5",
        ]]);

        $data = $this->verifyResponse(200);
        $repo = self::$dm->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $repo->find($data['id']);

        $discount = new PolicyDiscountPayment();
        $discount->setAmount(10);
        $discount->setDate(\DateTime::createFromFormat('U', time()));
        $policy->addPayment($discount);
        $policy->getPremium()->setAnnualDiscount($discount->getAmount());
        static::$dm->flush();
        
        // Now make sure that the policy shows up against the user
        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $policy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertGreaterThan(0, $data['monthly_premium']);
        $this->assertGreaterThan(0, $data['yearly_premium']);
        $this->assertTrue($data['monthly_premium'] > $data['adjusted_monthly_premium']);
        $this->assertTrue($data['yearly_premium'] > $data['adjusted_yearly_premium']);
        $this->assertEquals(1, count($data['premium_payments']['paid']));
        $this->assertEquals('policyDiscount', $data['premium_payments']['paid'][0]['type']);
    }

    public function testNewPolicy17DigitImei()
    {
        $this->clearRateLimit();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicy17DigitImei', $this),
            'foo',
            true
        );
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $imei = sprintf('%s/17', self::generateRandomImei());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'HTC',
            'device' => 'A0001',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
            'name' => "Kelvin’s iPhone5",
        ]]);

        $data = $this->verifyResponse(200);
    }

    public function testNewPolicyBlankPhoneName()
    {
        $this->clearRateLimit();
        $user = self::createUser(self::$userManager, self::generateEmail('blankphonename', $this), 'foo', true);
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
            'name' => "",
        ]]);

        $data = $this->verifyResponse(200);

        $this->assertTrue(mb_strlen($data['id']) > 5);
        $this->assertTrue(in_array('A0001', $data['phone_policy']['phone']['devices']));
        $this->assertGreaterThan(0, $data['monthly_premium']);
        $this->assertGreaterThan(0, $data['yearly_premium']);
        $this->assertEquals("Foo's One", $data['phone_policy']['name']);
    }

    public function testNewPolicyNotRegulated()
    {
        $this->clearRateLimit();
        $user = self::createUser(self::$userManager, self::generateEmail('policy-notreg', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $imei = self::generateRandomImei();

        $redis = $this->getRedis();
        $redis->set('ERROR_NOT_YET_REGULATED', 1);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'HTC',
            'device' => 'A0001',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);

        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_YET_REGULATED);

        $redis->del(['ERROR_NOT_YET_REGULATED']);
    }

    public function testNewPolicyInvalidValidation()
    {
        $this->clearRateLimit();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyInvalidValidation', $this),
            'foo',
            true
        );
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
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => 'not-an-imei']),
        ]]);

        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION);
    }

    public function testNewPolicyInvalidValidationDevice()
    {
        $this->clearRateLimit();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyInvalidValidationDevice', $this),
            'foo',
            true
        );
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
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei, 'device' => 'foo']),
        ]]);

        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION);
    }

    public function testNewPolicyInvalidValidationMemory()
    {
        $this->clearRateLimit();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyInvalidValidationMemory', $this),
            'foo',
            true
        );
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
            'validation_data' => $this->getValidationData(
                $cognitoIdentityId,
                ['imei' => $imei, 'device' => 'A0001', 'memory' => 1]
            ),
        ]]);

        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION);
    }

    public function testNewPolicyInvalidValidationRooted()
    {
        $this->clearRateLimit();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyInvalidValidationRooted', $this),
            'foo',
            true
        );
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
            'validation_data' => $this->getValidationData(
                $cognitoIdentityId,
                ['imei' => $imei, 'device' => 'A0001', 'memory' => 64, 'rooted' => true]
            ),
        ]]);

        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION);
    }

    public function testNewPolicyInvalidValidationSerial()
    {
        $this->clearRateLimit();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyInvalidValidationSerial', $this),
            'foo',
            true
        );
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $imei = self::generateRandomImei();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'serial_number' => 'foo',
            'make' => 'HTC',
            'device' => 'A0001',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData(
                $cognitoIdentityId,
                ['imei' => $imei, 'device' => 'A0001', 'memory' => 64, 'rooted' => false, 'serial_number' => 'bar']
            ),
        ]]);

        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_INVALID_VALIDATION);
    }

    public function testNewPolicyWithModelNumber()
    {
        $this->clearRateLimit();
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyWithModelNumber', $this),
            'foo',
            true
        );
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $imei = self::generateRandomImei();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'serial_number' => '23423423342',
            'model_number' => 'foo',
            'make' => 'HTC',
            'device' => 'A0001',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData(
                $cognitoIdentityId,
                [
                    'imei' => $imei,
                    'device' => 'A0001',
                    'memory' => 64,
                    'rooted' => false,
                    'serial_number' => '23423423342'
                ]
            ),
        ]]);
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($user->getId());
        $policies = $user->getPolicies();
        $this->assertEquals(1, count($policies));
        $this->assertEquals('foo', $policies[0]->getModelNumber());
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
            'device' => 'iPhone 8',
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
            'device' => 'iPhone 8',
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
            'device' => 'iPhone 8',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_DUPLICATE_IMEI);
    }

    public function testNewPolicyExpiredDuplicateImei()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = $this->generatePolicy($cognitoIdentityId, self::$testUser);
        $data = $this->verifyResponse(200);

        $policyId = $data['id'];
        $imei = $data['phone_policy']['imei'];
        
        $repo = static::$dm->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $repo->find($policyId);
        $policy->setStatus(Policy::STATUS_EXPIRED);
        static::$dm->flush();

        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 8',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(200);
    }

    public function testNewPolicyRateLimited()
    {
        $this->clearRateLimit();
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $limit = RateLimitService::$maxIpRequests[RateLimitService::DEVICE_TYPE_POLICY];
        for ($i = 1; $i <= $limit + 1; $i++) {
            $user = self::createUser(
                self::$userManager,
                self::generateEmail(sprintf('rate-limit-email-%d', $i), $this),
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
                    'memory' => 63,
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
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
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
            'device' => 'iPhone 8',
            'memory' => 1024,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testNewPolicyMemoryStandard()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 8',
            'memory' => 60,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(200);

        $this->assertTrue(mb_strlen($data['id']) > 5);
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
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::INVALID_IMEI]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_INVALID);
    }

    public function testNewPolicyBlacklistedImei()
    {
        $user = self::createUser(self::$userManager, self::generateEmail('policy-blacklist', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => self::BLACKLISTED_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::BLACKLISTED_IMEI]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_BLACKLISTED);

        // Ensure that policy wasn't created
        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($user->getId());
        $this->assertEquals(0, count($user->getPolicies()));
    }

    public function testNewPolicyLostStolenImei()
    {
        $lostPhone = new LostPhone();
        $lostPhone->setImei(self::LOSTSTOLEN_IMEI);
        self::$dm->persist($lostPhone);

        $user = self::createUser(self::$userManager, self::generateEmail('policy-loststolen', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => self::LOSTSTOLEN_IMEI,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => self::LOSTSTOLEN_IMEI]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_LOSTSTOLEN);
    }

    public function testNewPolicyMismatchPhone()
    {
        $user = self::createUser(self::$userManager, self::generateEmail('policy-mismatch', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
            'serial_number' => ReceperioService::TEST_INVALID_SERIAL,
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_PHONE_MISMATCH);
    }

    public function testNewPolicyOkPhone()
    {
        $user = self::createUser(self::$userManager, self::generateEmail('policy-ok-phone', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
            'serial_number' => "23423423342",
        ]]);
        $data = $this->verifyResponse(200);
        $this->assertEquals("Foo's One", $data['phone_policy']['name']);
    }

    public function testNewPolicyInvactivePhone()
    {
        $user = self::createUser(self::$userManager, self::generateEmail('policy-inactive-phone', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 4',
            'memory' => 15,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
            'serial_number' => "23423423342",
        ]]);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone',
            'memory' => 15,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
            'serial_number' => "23423423342",
        ]]);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
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

    public function testNewPolicyYoungUser()
    {
        $this->clearRateLimit();
        $userYoung = self::createUser(self::$userManager, self::generateEmail('young', $this), 'foo');

        $now = \DateTime::createFromFormat('U', time());
        $userYoung->setBirthday(new \DateTime(sprintf("%d-01-01", $now->format('Y'))));
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($userYoung);
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'Apple',
            'device' => 'iPhone 8',
            'memory' => 64,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS);
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

    /**
     *
     */
    public function testPostPolicy()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPostPolicy', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['phone_policy' => [
            'model_number' => '20$00[00]',
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['phone_policy' => []]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['phone_policy' => [
            'model_number' => 'MLLN2B/A',
        ]]);
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($user->getId());
        $policies = $user->getPolicies();
        $this->assertEquals(1, count($policies));
        $this->assertEquals('MLLN2B/A', $policies[0]->getModelNumber());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['phone_policy' => [
            'make' => 'Apple',
            'device' => 'iPhone11,2',
        ]]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['phone_policy' => [
            'make' => 'Apple',
            'device' => 'iPhone11,0',
            'memory' => 32,
        ]]);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['phone_policy' => [
            'make' => 'Apple',
            'device' => 'iPhone11,2',
            'memory' => 64,
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_UNABLE_TO_UDPATE);

        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);
        $url = sprintf("/api/v1/auth/policy/%s", $policyData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['phone_policy' => [
            'make' => 'Apple',
            'device' => 'iPhone11,2',
            'memory' => 64,
        ]]);
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($user->getId());
        $policies = $user->getPolicies();
        $this->assertEquals(2, count($policies));
        $this->assertNotNull($policies[1]->getPhone());
        $this->assertEquals('Apple', $policies[1]->getPhone()->getMake());
        $this->assertEquals('iPhone XS', $policies[1]->getPhone()->getModel());
        $this->assertEquals(64, $policies[1]->getPhone()->getMemory());
        $this->assertEquals(
            $policies[1]->getPhone()->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            $policies[1]->getPremium()->getMonthlyPremiumPrice()
        );
    }

    public function testPostPolicyUnknownId()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/policy/1');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['phone_policy' => []]);
        $data = $this->verifyResponse(404);
    }

    public function testPostPolicyUnAuthUser()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPostPolicyUnAuthUser', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $user = static::createUser(self::$userManager, 'postpolicy-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/policy/%s', $policyData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['phone_policy' => []]);
        $data = $this->verifyResponse(403);
    }

    // policy/{id}/billing-day

    /**
     *
     */
    public function testBillingDay()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testBillingDay', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/billing-day", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'billing_day' => '0',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'billing_day' => '29',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'billing_day' => '9',
        ]);
        $data = $this->verifyResponse(200);
    }

    // policy/{id}/connect

    /**
     *
     */
    public function testMultiPolicyConnect()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testConnect', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyDataA = $this->verifyResponse(200);

        $this->payPolicy($user, $policyDataA['id']);

        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyDataB = $this->verifyResponse(200);

        $this->payPolicy($user, $policyDataB['id']);

        $url = sprintf("/api/v1/auth/policy/%s/connect", $policyDataA['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'policy_id' => $policyDataB['id']
        ]);
        $data = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/policy/%s/connect", $policyDataA['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'policy_id' => $policyDataB['id']
        ]);
        $data = $this->verifyResponse(422);

        $url = sprintf("/api/v1/auth/policy/%s/connect", $policyDataB['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'policy_id' => $policyDataA['id']
        ]);
        $data = $this->verifyResponse(422);
    }

    // policy/{id}/pay dd

    /**
     *
     */
    public function testNewPolicyDdMissingData()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'sort_code' => '333333',
                'account_number' => '12345678',
            ]
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'account_number' => '12345678',
                'account_name' => 'foo bar',
            ]
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'sort_code' => '12345678',
                'account_name' => 'foo bar',
            ]
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'account_number' => '12345678',
                'sort_code' => '12345678',
                'account_name' => 'foo bar',
            ]
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'account_number' => '12345678',
                'sort_code' => '12345678',
                'mandate' => 'R012345678',
            ]
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'account_name' => 'foo bar',
                'sort_code' => '12345678',
                'mandate' => 'R012345678',
            ]
        ]);
        $data = $this->verifyResponse(400);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'account_name' => 'foo bar',
                'account_number' => '12345678',
                'mandate' => 'R012345678',
            ]
        ]);
        $data = $this->verifyResponse(400);
    }

    public function testNewPolicyDdUnknownPolicy()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy/1/pay', [
            'bank_account' => [
                'sort_code' => PCAService::TEST_SORT_CODE,
                'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
                'account_name' => 'foo bar',
                'mandate' => 'R012345678',
                'initial_amount' => 0,
                'recurring_amount' => 0,
            ]
        ]);
        $data = $this->verifyResponse(404);
    }

    public function testNewPolicyPayNotRegulated()
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

        $redis = $this->getRedis();
        $redis->set('ERROR_NOT_YET_REGULATED', 1);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => self::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_YET_REGULATED);

        $redis->del(['ERROR_NOT_YET_REGULATED']);
    }

    public function testNewPolicyPayBacsOk()
    {
        /** @var User $user */
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyPayBacsOk', $this, true),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => self::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(200);
        $this->assertEquals(Policy::STATUS_ACTIVE, $data['status']);
        $this->assertEquals('BX1 1LT', $data['bank_account']['bank_address']['postcode']);
        $this->assertEquals('pending-init', $data['bank_account']['mandate_status']);
        $this->assertGreaterThan(5, mb_strlen($data['bank_account']['mandate']));
        $this->assertGreaterThan(8, mb_strlen($data['bank_account']['initial_notification_date']));
        $this->assertGreaterThan(0, $data['bank_account']['standard_notification_day']);
        $this->assertTrue($data['has_time_bacs_payment']);
    }

    public function testNewPolicyPayBacsExisting()
    {
        /** @var User $user */
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyPayBacsExisting', $this, true),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => self::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(200);
        $this->assertEquals(Policy::STATUS_ACTIVE, $data['status']);
        $this->assertEquals('BX1 1LT', $data['bank_account']['bank_address']['postcode']);
        $this->assertEquals('pending-init', $data['bank_account']['mandate_status']);
        $this->assertGreaterThan(5, mb_strlen($data['bank_account']['mandate']));
        $this->assertGreaterThan(8, mb_strlen($data['bank_account']['initial_notification_date']));
        $this->assertGreaterThan(0, $data['bank_account']['standard_notification_day']);
        $this->assertTrue($data['has_time_bacs_payment']);

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['existing' => [
            'amount' => 5
        ]]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('5.00', $data['premium_payments']['paid'][0]['amount']);
    }

    public function testNewPolicyPayBacsNotEnoughTime()
    {
        /** @var User $user */
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyPayBacsNotEnoughTime', $this, true),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => static::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $twoMonthsAgo = $this->now();
        $twoMonthsAgo = $twoMonthsAgo->sub(new \DateInterval('P2M'));
        $updatedPolicy->setStart($twoMonthsAgo);
        static::$dm->flush();
        //$this->assertFalse($updatedPolicy->isPolicyPaidToDate());
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => self::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_NOT_ENOUGH_TIME);
    }

    public function testNewPolicyPayBacsNameMismatch()
    {
        /** @var User $user */
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyPayBacsNameMismatch', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => sprintf('%sbad', $user->getName()),
            'mandate' => self::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_NAME_MISMATCH);
    }

    public function testNewPolicyPayBacsDuplicateMandate()
    {
        $mandate = self::generateRandomImei();
        /** @var User $user */
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyPayBacsDuplicateMandate-A', $this, true),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => $mandate,
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(200);

        /** @var User $user */
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyPayBacsDuplicateMandate-B', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => sprintf('%s', $user->getName()),
            'mandate' => $mandate,
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_INVALID_MANDATE);
    }

    public function testNewPolicyPayBacsInvalidSortCode()
    {
        /** @var User $user */
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyPayBacsInvalidSortCode', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_INVALID_SORT_CODE,
            'account_name' => 'foo bar',
            'mandate' => self::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_INVALID_SORTCODE);
    }

    public function testNewPolicyPayBacsInvalidAccountNumber()
    {
        /** @var User $user */
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyPayBacsInvalidAccountNumber', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_INVALID_ACCOUNT_NUMBER,
            'account_name' => 'foo bar',
            'mandate' => self::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_INVALID_NUMBER);
    }

    public function testNewPolicyPayBacsNoDd()
    {
        /** @var User $user */
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyPayBacsNoDd', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_NO_DD,
            'account_name' => 'foo bar',
            'mandate' => self::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_DIRECT_DEBIT_UNAVAILABLE);
    }

    public function testNewPolicyPayBacsInvalidAmount()
    {
        /** @var User $user */
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyPayBacsInvalidAmount', $this, true),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => self::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice() + 0.01,
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice() + 0.01,
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_INVALID_AMOUNT);
    }

    public function testNewPolicyJudopayOk()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);

        $promoCode = null;
        $now = \DateTime::createFromFormat('U', time());
        if ($now->format('Y-m') == '2016-11') {
            $promoCode = Policy::PROMO_FREE_NOV;
        } elseif ($now->format('Y-m') == '2016-12') {
            $promoCode = Policy::PROMO_FREE_DEC_2016;
        }

        $this->assertEquals($promoCode, $policyData['promo_code']);
        $this->assertEquals(7, $policyData['pot']['max_connections'], json_encode($policyData));
        // $this->assertEquals(83.88, $policyData['pot']['max_value']);
        // 6.38 gwp * 1.1 = 7.018 * 12 = 84.24 * .8 = 67.39
        $this->assertEquals(68.64, $policyData['pot']['max_value']);
        $this->assertEquals(7.15, $policyData['premium']);
        $this->assertEquals(6.38, $policyData['premium_gwp']);
        $highConnectionValue = 0;
        $lowConnectionValue = null;
        foreach ($policyData['pot']['connection_values'] as $connectionValue) {
            if ($connectionValue['value'] > $highConnectionValue) {
                $highConnectionValue = $connectionValue['value'];
            }
            if (!$lowConnectionValue || $connectionValue['value'] < $lowConnectionValue) {
                $lowConnectionValue = $connectionValue['value'];
            }
        }
        if ($promoCode == 'launch') {
            $this->assertEquals(15, $highConnectionValue);
        } else {
            $this->assertEquals(10, $highConnectionValue);
        }
        $this->assertEquals(2, $lowConnectionValue);
        $this->assertEquals(1, count($policyData['premium_payments']['paid']));
        $this->assertEquals('judo', $policyData['premium_payments']['paid'][0]['type']);
    }

    public function testNewPolicyWithPremiumValidation()
    {
        $dm = $this->getDocumentManager(true);
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-different-amount', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user, true, null, $this->getRandomPhone($dm));
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($data['id']);
        $phone = $policy->getPhone();

        $currentPrice = $phone->getCurrentPhonePrice();

        $validFrom = \DateTime::createFromFormat('U', time());
        $validFrom->sub(new \DateInterval('PT1H'));

        $price = new PhonePrice();
        if ($currentPrice) {
            $price->setGwp($currentPrice->getGwp() + 1);
            $currentPrice->setValidTo($validFrom);
        }
        $price->setValidFrom($validFrom);
        $phone->addPhonePrice($price);


        $dm->flush();

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            $price->getMonthlyPremiumPrice(),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);
    }

    public function testNewPolicyJudopayExceptionPartialPolicy()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyJudopayExceptionPartialPolicy', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        // Simulate an exception occuring - policy status will be pending, but nothing else occurred
        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $repo->find($data['id']);
        $updatedPolicy->setStatus(Policy::STATUS_PENDING);
        $dm->flush();

        $url = sprintf("/api/v1/auth/user?_method=GET");
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $policyData = $this->verifyResponse(200);
        // print_r($policyData);
    }

    public function testNewPolicyJudopayInvalidUserDetails()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-invalid-user', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(User::class);
        /** @var User $updatedUser */
        $updatedUser = $repo->find($user->getId());
        $updatedUser->setMobileNumber(null);
        $dm->flush();

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_INVALID_USER_DETAILS);
    }

    public function testNewPoliciesJudopayDuplicateReceiptNotAllowed()
    {
        $userB = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-dupB', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($userB);
        $crawler = $this->generatePolicy($cognitoIdentityId, $userB);
        $dataB = $this->verifyResponse(200);

        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-dupA', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($userA);
        $crawler = $this->generatePolicy($cognitoIdentityId, $userA);
        $dataA = $this->verifyResponse(200);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $userA,
            $dataA['id'],
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $dataA['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);

        $cognitoIdentityId = $this->getAuthUser($userB);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $dataB['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_REQUIRED);
    }

    public function testNewPolicyJudopayDeclined()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-declined', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            '4221 6900 0000 4963',
            '12/20',
            '125'
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => null,
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($data['id']);

        // ensure status is reset to null to avoid trigger monitoring alerts
        $this->assertNull($policy->getStatus());
    }

    public function testExistingPolicyJudopayDeclined()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testExistingPolicyJudopayDeclined', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);

//        $policyData = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($data['id']);

        $receiptId = $judopay->testPay(
            $user,
            sprintf('%sA', $data['id']),
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            '4221 6900 0000 4963',
            '12/20',
            '125'
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => null,
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($data['id']);
        // Policy was active - should not be chaging state if so
        $this->assertNotNull($policy->getStatus());
    }

    public function testNewPolicyJudopayInvalidPremium()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-invalidpremium', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '1.01',
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_INVALID_AMOUNT);
    }

    public function testNewPolicyJudopayDuplicate()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-dup', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_REQUIRED);

        // Ensure that policy creation didn't run twice
        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($policyData['id']);
        $this->assertEquals(11, count($policy->getScheduledPayments()));
    }

    public function testNewPolicyJudopayUnpaidRepayOk()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-judopay-repay', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200001',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);

        // Ensure that policy creation didn't run twice
        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($policyData['id']);
        //\Doctrine\Common\Util\Debug::dump($policy->getSuccessfulPayments());
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(7.15, $policy->getTotalSuccessfulPayments());

        // Now assume an unpaid payment
        $policy->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        $dm->flush();

        $receiptId = $judopay->testPay(
            $user,
            sprintf("%s%d", $data['id'], rand(1, 999999)),
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200001',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($policyData['id']);
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        $this->assertEquals(7.15 * 2, $policy->getTotalSuccessfulPayments());

        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice(), $policyData['premium']);
        $this->assertEquals('monthly', $policyData['premium_plan']);
        $environment = $this->getContainer(true)->getParameter('kernel.environment');
        $this->assertTrue($policy->arePolicyScheduledPaymentsCorrect());
        $this->assertEquals(1, count($policy->getAllScheduledPayments(ScheduledPayment::STATUS_CANCELLED)));
    }

    public function testNewPolicyJudopayUnpaidRepayBacsOk()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyJudopayUnpaidRepayBacsOk', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200001',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);

        // Ensure that policy creation didn't run twice
        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($policyData['id']);
        //\Doctrine\Common\Util\Debug::dump($policy->getSuccessfulPayments());
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(7.15, $policy->getTotalSuccessfulPayments());

        // Now assume an unpaid payment
        $policy->setStatus(SalvaPhonePolicy::STATUS_UNPAID);
        $dm->flush();

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => self::generateRandomImei(),
            'initial_amount' => $policy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $policy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $data['status']);
        $this->assertEquals($data['id'], $policyData['id']);
        $this->assertEquals(Policy::STATUS_ACTIVE, $data['status']);
        $this->assertEquals('BX1 1LT', $data['bank_account']['bank_address']['postcode']);
        $this->assertEquals('pending-init', $data['bank_account']['mandate_status']);
        $this->assertGreaterThan(5, mb_strlen($data['bank_account']['mandate']));
        $this->assertGreaterThan(8, mb_strlen($data['bank_account']['initial_notification_date']));
        $this->assertGreaterThan(0, $data['bank_account']['standard_notification_day']);
        $this->assertTrue($data['has_time_bacs_payment']);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($policyData['id']);
        $this->assertEquals(11, count($policy->getScheduledPayments()));
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policy->getStatus());
        // bacs is pending, so still only 1 payment received
        $this->assertEquals(7.15 * 1, $policy->getTotalSuccessfulPayments());

        $this->assertEquals($policy->getPremium()->getMonthlyPremiumPrice(), $policyData['premium']);
        $this->assertEquals('monthly', $policyData['premium_plan']);
        $environment = $this->getContainer(true)->getParameter('kernel.environment');
        $this->assertTrue($policy->arePolicyScheduledPaymentsCorrect());
    }

    public function testNewPolicyJudopayMissingReceipt()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyJudopayMissingReceipt', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '',
            'card_token' => '',
            'receipt_id' => '',
        ]]);
        $policyData = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testNewMulitplePolicyJudopayOk()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewMulitplePolicyJudopayOk', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $details = $judopay->testPayDetails(
            $user,
            $data['id'],
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => $details['consumer']['consumerToken'],
            'card_token' => $details['cardDetails']['cardToken'],
            'receipt_id' => $details['receiptId'],
        ]]);
        $policyData11 = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData11['status']);
        $this->assertEquals($data['id'], $policyData11['id']);

        $updatedPolicy = $this->assertPolicyByIdExists($this->getContainer(true), $policyData11['id']);
        $this->assertNotNull($updatedPolicy->getStatus());
        $this->assertTrue($updatedPolicy->hasPolicyOrUserValidPaymentMethod());
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $updatedPolicy->getStatus());

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['existing' => [
            'amount' => '7.15'
        ]]);
        $policyData12 = $this->verifyResponse(200);
        $this->assertEquals($data['id'], $policyData12['id']);

        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);
        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['existing' => [
            'amount' => '7.15'
        ]]);
        $policyData2 = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_REQUIRED);
    }

    public function testNewPolicyMultipayDeclinedJudopayOk()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testNewPolicyMultipayDeclinedJudopayOk', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        $repo = static::$dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($data['id']);
        $policy->setStatus(PhonePolicy::STATUS_MULTIPAY_REJECTED);
        static::$dm->flush();

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $receiptId = $judopay->testPay(
            $user,
            $data['id'],
            '7.15', // gwp 6.38 was 6.99 (9.5% ipt), now 7.02 (10% ipt), now 7.15 (12%)
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/pay", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => '200000',
            'card_token' => '55779911',
            'receipt_id' => $receiptId,
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_ACTIVE, $policyData['status']);
        $this->assertEquals($data['id'], $policyData['id']);
    }

    // policy/{id}/payment

    /**
     *
     */
    public function testUpdatePolicyPaymentJudopayOk()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentJudopayOk', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $details = $judopay->testRegisterDetails(
            $user,
            rand(1, 999999),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => $details['consumer']['consumerToken'],
            'card_token' => $details['cardDetails']['cardToken'],
            'receipt_id' => $details['receiptId'],
        ]]);
        $data = $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(self::$JUDO_TEST_CARD_NAME, $data['payment_details']);
        $this->assertEquals('judo', $data['payment_method']);
        $this->assertEquals(self::$JUDO_TEST_CARD_EXP_DATE, $data['card_details']['end_date']);
        $this->assertEquals(self::$JUDO_TEST_CARD_TYPE, $data['card_details']['type']);
        $this->assertEquals(self::$JUDO_TEST_CARD_LAST_FOUR, $data['card_details']['last_four']);

        $details = $judopay->testRegisterDetails(
            $user,
            rand(1, 999999),
            self::$JUDO_TEST_CARD2_NUM,
            self::$JUDO_TEST_CARD2_EXP,
            self::$JUDO_TEST_CARD2_PIN
        );

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => $details['consumer']['consumerToken'],
            'card_token' => $details['cardDetails']['cardToken'],
            'receipt_id' => $details['receiptId'],
        ]]);
        $data = $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(self::$JUDO_TEST_CARD2_NAME, $data['payment_details']);
        $this->assertEquals('judo', $data['payment_method']);
        $this->assertEquals(self::$JUDO_TEST_CARD2_EXP_DATE, $data['card_details']['end_date']);
        $this->assertEquals(self::$JUDO_TEST_CARD2_TYPE, $data['card_details']['type']);
        $this->assertEquals(self::$JUDO_TEST_CARD2_LAST_FOUR, $data['card_details']['last_four']);
    }

    public function testUpdatePolicyPaymentBacsOk()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentBacsOk', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => static::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertContains(PCAService::TEST_ACCOUNT_NUMBER_OK_DISPLAY, $data['payment_details']);
        $this->assertEquals('bacs', $data['payment_method']);

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_ADJUSTED,
            'account_name' => 'foo bar',
            'mandate' => static::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertContains(PCAService::TEST_ACCOUNT_NUMBER_ADJUSTED_DISPLAY, $data['payment_details']);
        $this->assertEquals('bacs', $data['payment_method']);
        $this->assertEquals(null, $data['card_details']['end_date']);
        $this->assertEquals('Unknown', $data['card_details']['type']);
        $this->assertEquals(null, $data['card_details']['last_four']);
    }

    public function testUpdatePolicyPaymentBacsDuplicateMandate()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentBacsDuplicateMandate', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        $mandate = static::generateRandomImei();
        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => $mandate,
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/policy/%s?_method=GET', $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertContains(PCAService::TEST_ACCOUNT_NUMBER_OK_DISPLAY, $data['payment_details']);
        $this->assertEquals('bacs', $data['payment_method']);

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_ADJUSTED,
            'account_name' => 'foo bar',
            'mandate' => $mandate,
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_INVALID_MANDATE);
    }

    public function testUpdatePolicyPaymentJudopayFail()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentJudopayFail', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);
        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $details = $judopay->testRegisterDetails(
            $user,
            rand(1, 999999),
            '4221 6900 0000 4963',
            '12/20',
            '125'
        );

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => $details['consumer']['consumerToken'],
            'card_token' => 'unknown',
            'receipt_id' => $details['receiptId'],
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED);
    }

    public function testUpdatePolicyPaymentBacsInvalidSortCode()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentBacsInvalidSortCode', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);
        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_INVALID_SORT_CODE,
            'account_name' => 'foo bar',
            'mandate' => static::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_INVALID_SORTCODE);
    }

    public function testUpdatePolicyPaymentBacsInvalidAccountNumber()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentBacsInvalidAccountNumber', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);
        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_INVALID_ACCOUNT_NUMBER,
            'account_name' => 'foo bar',
            'mandate' => static::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_INVALID_NUMBER);
    }

    public function testUpdatePolicyPaymentBacsNoDd()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentBacsNoDd', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);
        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_NO_DD,
            'account_name' => 'foo bar',
            'mandate' => static::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_DIRECT_DEBIT_UNAVAILABLE);
    }

    public function testUpdatePolicyPaymentBacsNameMismatch()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentBacsNameMismatch', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);
        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo barr',
            'mandate' => static::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice(),
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_BANK_NAME_MISMATCH);
    }

    public function testUpdatePolicyPaymentBacsInvalidAmount()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentBacsInvalidAmount', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);
        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => PCAService::TEST_SORT_CODE,
            'account_number' => PCAService::TEST_ACCOUNT_NUMBER_OK,
            'account_name' => 'foo bar',
            'mandate' => static::generateRandomImei(),
            'initial_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice() + 0.01,
            'recurring_amount' => $updatedPolicy->getPremium()->getMonthlyPremiumPrice() + 0.01,
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_INVALID_AMOUNT);
    }

    public function testUpdatePolicyPaymentJudopayNoData()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentJudopayNoData', $this),
            'foo'
        );
        $user->setFirstName('foo');
        $user->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $data = $this->verifyResponse(200);
        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
        ]]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testUpdatePolicyPaymentJudopayDiffUser()
    {
        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentJudopayDiffUser-A', $this),
            'foo'
        );
        $userB = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentJudopayDiffUser-B', $this),
            'foo'
        );
        $userA->setFirstName('foo');
        $userA->setLastName('bar');
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($userA);
        $crawler = $this->generatePolicy($cognitoIdentityId, $userA);
        $data = $this->verifyResponse(200);
        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $data['id']);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $details = $judopay->testRegisterDetails(
            $userA,
            rand(1, 999999),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $cognitoIdentityId = $this->getAuthUser($userB);
        $url = sprintf("/api/v1/auth/policy/%s/payment", $updatedPolicy->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => $details['consumer']['consumerToken'],
            'card_token' => $details['cardDetails']['cardToken'],
            'receipt_id' => $details['receiptId'],
        ]]);
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_ACCESS_DENIED);
    }

    public function testUpdatePolicyPaymentJudopayUnknownPolicy()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdatePolicyPaymentJudopayUnknownPolicy', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $url = sprintf("/api/v1/auth/policy/%s/payment", 'foo');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => 'foo',
            'card_token' => 'foo',
            'receipt_id' => 'foo',
        ]]);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    // policy/{id}/terms

    /**
     *
     */
    public function testPolicyTerms()
    {
        $user = self::createUser(self::$userManager, self::generateEmail('policy-ok-terms', $this), 'foo', true);
        self::addAddress($user);
        self::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->clearRateLimit();
        $imei = self::generateRandomImei();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', ['phone_policy' => [
            'imei' => $imei,
            'make' => 'OnePlus',
            'device' => 'A0001',
            'memory' => 63,
            'rooted' => false,
            'validation_data' => $this->getValidationData($cognitoIdentityId, ['imei' => $imei]),
            'serial_number' => "23423423342",
        ]]);
        $data = $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/policy/%s/terms?_method=GET', $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
    }

    // policy/{id}/cashback

    /**
     *
     */
    public function testCashback()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testCashback', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/cashback", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'account_name' => 'foo',
            'account_number' => '123',
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'sort_code' => 'foo',
            'account_number' => '123',
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'account_name' => 'foo',
            'sort_code' => '123',
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'account_name' => 'foo',
            'sort_code' => '123',
            'account_number' => '123',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'account_name' => 'foo',
            'sort_code' => '123456',
            'account_number' => '12345678',
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('pending-claimable', $data['cashback_status']);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'account_name' => 'bar',
            'sort_code' => '654321',
            'account_number' => '87654321',
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('pending-claimable', $data['cashback_status']);
    }

    // policy/{id}/invitation

    /**
     *
     */
    public function testNewEmailAndCancelledDupInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-email', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'patrick@so-sure.org',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('patrick@so-sure.org', $data['invitation_detail']);
        $this->assertEquals('functional test', $data['name']);

        $cancelUrl = sprintf("/api/v1/auth/invitation/%s", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $cancelUrl, [
            'action' => 'cancel',
            'policy_id' => $policyData['id'],
        ]);
        $data = $this->verifyResponse(200);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'patrick@so-sure.org',
            'name' => 'functional test',
        ]);
        $this->verifyResponse(200);
    }

    /*
     * Test would only work against a prod env server
    public function testSoSureEmailInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('sosure-email', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'patrick@so-sure.com',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_OPTOUT);
    }
    */

    /**
     */
    public function testNewEmailAndRejectedDupInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-email-rejected', $this),
            'foo'
        );
        $userInvitee = self::createUser(
            self::$userManager,
            self::generateEmail('new-email-rejected-invitee', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);

        $cognitoIdentityInviteeId = $this->getAuthUser($userInvitee);
        $crawler = $this->generatePolicy($cognitoIdentityInviteeId, $userInvitee);
        $policyInviteeData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('new-email-rejected-invitee', $this),
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals(
            mb_strtolower(self::generateEmail('new-email-rejected-invitee', $this)),
            $data['invitation_detail']
        );
        $this->assertEquals('functional test', $data['name']);

        $rejectUrl = sprintf("/api/v1/auth/invitation/%s", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityInviteeId, $rejectUrl, [
            'action' => 'reject',
            'policy_id' => $policyInviteeData['id'],
        ]);
        $data = $this->verifyResponse(200);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('new-email-rejected-invitee', $this),
            'name' => 'functional test',
        ]);
        $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_DUPLICATE);
    }

    public function testDuplicateEmailInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('DuplicateEmailInvitation-user', $this),
            'foo'
        );
        $userInvitee = self::createUser(
            self::$userManager,
            self::generateEmail('DuplicateEmailInvitation-invitee', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);

        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('DuplicateEmailInvitation-invitee', $this),
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals(
            mb_strtolower(self::generateEmail('DuplicateEmailInvitation-invitee', $this)),
            $data['invitation_detail']
        );
        $this->assertEquals('functional test', $data['name']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('DuplicateEmailInvitation-invitee', $this),
            'name' => 'functional test',
        ]);
        $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_DUPLICATE);
    }

    public function testEmailValidationInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-email-validate', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => 'notanemail',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testNewSmsAndCancelledDupInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-sms', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900002',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('+447700900002', $data['invitation_detail']);
        $this->assertEquals('functional test', $data['name']);

        $cancelUrl = sprintf("/api/v1/auth/invitation/%s", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $cancelUrl, [
            'action' => 'cancel',
            'policy_id' => $policyData['id'],
        ]);
        $data = $this->verifyResponse(200);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900002',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(200);
    }

    public function testNewSmsAndRejectedDupInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-sms-rejected', $this),
            'foo'
        );
        $userInvitee = self::createUser(
            self::$userManager,
            self::generateEmail('new-sms-rejected-invitee', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);

        $cognitoIdentityInviteeId = $this->getAuthUser($userInvitee);
        $crawler = $this->generatePolicy($cognitoIdentityInviteeId, $userInvitee);
        $policyInviteeData = $this->verifyResponse(200);

        $userInvitee->setMobileNumber('+447700900003');
        static::$dm->flush();

        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900003',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('+447700900003', $data['invitation_detail']);
        $this->assertEquals('functional test', $data['name']);

        $rejectUrl = sprintf("/api/v1/auth/invitation/%s", $data['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityInviteeId, $rejectUrl, [
            'action' => 'reject',
            'policy_id' => $policyInviteeData['id'],
        ]);
        $data = $this->verifyResponse(200);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900003',
            'name' => 'functional test',
        ]);
        $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_DUPLICATE);
    }

    public function testSmsSkipSendInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('sms-skip-send', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => '+447700900004',
            'name' => 'functional test',
            'skip_send' => true,
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('+447700900004', $data['invitation_detail']);
        $this->assertEquals('functional test', $data['name']);

        // sending sms should trigger a charge db entry
        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Charge::class);
        /** @var Charge $charge */
        $charge = $repo->findOneBy(['details' => '+447700900004']);
        $this->assertNull($charge);
    }

    public function testSmsValidationInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-sms-validate', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'mobile' => 'notasms',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testNewSCodeInvitation()
    {
        $inviter = self::createUser(
            self::$userManager,
            self::generateEmail('inviter-scode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($inviter);
        $crawler = $this->generatePolicy($cognitoIdentityId, $inviter);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($inviter, $policyData['id']);

        $dm = $this->getDocumentManager(true);
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $policyRepo->find($policyData['id']);
        $scode = $policy->getStandardSCode()->getCode();

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-scode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'scode' => $scode,
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('foo bar', $data['name']);
    }

    public function testNameConformInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invite-name-conform', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'name' => 'functional test$',
            'email' => $this->generateEmail('invite-name-conform-invite', $this)
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('functional test', $data['name']);
    }

    public function testSCodeValidationInvitation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('new-scode-validate', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'scode' => 'not-an-scode',
            'name' => 'functional test',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testNewFacebookInvitation()
    {
        $inviter = self::createUser(
            self::$userManager,
            self::generateEmail('testNewFacebookInvitation-inviter', $this),
            'foo'
        );
        $inviter->setFacebookId(sprintf(sprintf('FACEBOOK-R%d', rand(1, 999999))));
        $cognitoIdentityId = $this->getAuthUser($inviter);
        $crawler = $this->generatePolicy($cognitoIdentityId, $inviter);
        $policyData = $this->verifyResponse(200);

        $inviterPolicyId = $policyData['id'];
        $this->payPolicy($inviter, $inviterPolicyId);

        $invitee = self::createUser(
            self::$userManager,
            self::generateEmail('testNewFacebookInvitation-invitee', $this),
            'foo'
        );
        $invitee->setFacebookId(sprintf(sprintf('FACEBOOK-I%d', rand(1, 999999))));
        static::$dm->persist($invitee);
        static::$dm->flush();
        $cognitoIdentityId = $this->getAuthUser($invitee);
        $crawler = $this->generatePolicy($cognitoIdentityId, $invitee);
        $policyData = $this->verifyResponse(200);

        $inviteePolicyId = $policyData['id'];
        $this->payPolicy($invitee, $policyData['id']);

        $cognitoIdentityId = $this->getAuthUser($inviter);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $inviterPolicyId);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'action' => 'accept',
            'facebook_id' => $invitee->getFacebookId()
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals('foo bar', $data['name']);
        $this->assertEquals(
            mb_strtolower(self::generateEmail('testNewFacebookInvitation-invitee', $this)),
            $data['invitation_detail']
        );

        $cognitoIdentityId = $this->getAuthUser($invitee);
        $url = sprintf("/api/v1/auth/user?_method=GET");
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals($inviter->getFacebookId(), $data['facebook_filters'][0]);

        $cognitoIdentityId = $this->getAuthUser($inviter);
        $url = sprintf("/api/v1/auth/policy/%s?_method=GET", $inviterPolicyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
        ]);
        $data = $this->verifyResponse(200);
        $this->assertEquals($invitee->getFacebookId(), $data['facebook_filters'][0]);
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

        $this->payPolicy($user, $policyData['id']);
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

        $this->payPolicy(self::$testUser2, $data['id']);
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
                // http://aruljohn.com/gravatar/
                // bar@auth-api.so-sure.com (testUser2) -> 0b1cac52ee6250748998bf4e2ccc29b1
                $this->assertEquals(
                    'https://www.gravatar.com/avatar/0b1cac52ee6250748998bf4e2ccc29b1?d=404&s=100',
                    $invitation['image_url']
                );
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

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('invitation-notself', $this),
            'name' => 'Invitation Name',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_SELF_INVITATION);
    }

    public function testUnableToInviteSelfScodeApp()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUnableToInviteSelfScodeApp', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::getContainer(true), $policyData['id']);
        $this->assertNotNull($updatedPolicy);
        if ($updatedPolicy) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
                'scode' => $updatedPolicy->getStandardSCode() ? $updatedPolicy->getStandardSCode()->getCode() : null
            ]);
            $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_SELF_INVITATION);

            $updatedPolicy = $this->assertPolicyExists(self::getContainer(true), $updatedPolicy);
            $this->assertCount(0, $updatedPolicy->getSentInvitations(false));
        }
    }

    public function testUnableToInviteSelfScodeJs()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUnableToInviteSelfScodeJs', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        /** @var Policy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::getContainer(true), $policyData['id']);
        $this->assertNotNull($updatedPolicy);
        if ($updatedPolicy) {
            $crawler = static::postRequest(
                self::$client,
                $cognitoIdentityId,
                $url,
                [
                    'scode' => $updatedPolicy->getStandardSCode() ? $updatedPolicy->getStandardSCode()->getCode() : null
                ],
                IdentityLog::SDK_JAVASCRIPT
            );
            $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_SELF_INVITATION);

            $updatedPolicy = $this->assertPolicyExists(self::getContainer(true), $updatedPolicy);
            $this->assertCount(0, $updatedPolicy->getSentInvitations(false));
        }
    }

    public function testUnableToCrossInvite()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('invitation-cross', $this),
            'foo'
        );
        $user2 = self::createUser(
            self::$userManager,
            self::generateEmail('cross-invitation', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'email' => self::generateEmail('cross-invitation', $this),
            'name' => 'Invitation Name',
        ]);
        $invitationData = $this->verifyResponse(200);

        $cognitoIdentityId2 = $this->getAuthUser($user2);
        $crawler = $this->generatePolicy($cognitoIdentityId2, $user2);
        $policyData2 = $this->verifyResponse(200);

        $this->payPolicy($user2, $policyData2['id']);
        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId2, $url, [
            'action' => 'accept',
            'policy_id' => $policyData2['id'],
        ]);

        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyData2['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId2, $url, [
            'email' => self::generateEmail('invitation-cross', $this),
            'name' => 'Invitation Name',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVITATION_CONNECTED);
    }


    // policy/{id}/imei

    /**
     *
     */
    public function testImei()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testImei', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/imei", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => 'foo',
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'key' => 'foo',
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => SoSure::S3_BUCKET_OPS,
            'key' => 'php-unit-tests/Controller/ApiAuthControllerTest/KEY_NOT_FOUND',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_FILE_NOT_FOUND);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => SoSure::S3_BUCKET_OPS,
            'key' => 'php-unit-tests/Controller/ApiAuthControllerTest/imei-invalid.txt',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_IMEI_FILE_INVALID);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => SoSure::S3_BUCKET_OPS,
            'key' => 'php-unit-tests/Controller/ApiAuthControllerTest/imei-valid-no-suspected-fraud.png',
        ]);
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $repo->find($policyData['id']);
        $this->assertFalse($updatedPolicy->getImeiCircumvention());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => SoSure::S3_BUCKET_OPS,
            'key' => 'php-unit-tests/Controller/ApiAuthControllerTest/imei-valid-suspected-fraud.png',
        ]);
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $repo->find($policyData['id']);
        $this->assertTrue($updatedPolicy->getImeiCircumvention());
    }

    // policy/{id}/picsure

    /**
     *
     */
    public function testPicsure()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPicsure', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/picsure", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => 'foo',
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'key' => 'foo',
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => SoSure::S3_BUCKET_OPS,
            'key' => 'php-unit-tests/Controller/ApiAuthControllerTest/KEY_NOT_FOUND',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PICSURE_FILE_NOT_FOUND);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $repo->find($policyData['id']);
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_INVALID, $updatedPolicy->getPicSureStatus());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => SoSure::S3_BUCKET_OPS,
            'key' => 'php-unit-tests/Controller/ApiAuthControllerTest/picsure-invalid.txt',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PICSURE_FILE_INVALID);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $repo->find($policyData['id']);
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_INVALID, $updatedPolicy->getPicSureStatus());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => SoSure::S3_BUCKET_OPS,
            'key' => 'php-unit-tests/Controller/ApiAuthControllerTest/picsure-valid1.jpg',
        ]);
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $repo->find($policyData['id']);
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_MANUAL, $updatedPolicy->getPicSureStatus());
        $this->assertFalse($updatedPolicy->getPicSureCircumvention());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => SoSure::S3_BUCKET_OPS,
            'key' => 'php-unit-tests/Controller/ApiAuthControllerTest/picsure-valid2.png',
        ]);
        $data = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $repo->find($policyData['id']);
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_MANUAL, $updatedPolicy->getPicSureStatus());
        $this->assertTrue($updatedPolicy->getPicSureCircumvention());
    }

    /**
     *
     */
    public function testPicsureWithValidS3File()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPicsureWithValidS3File', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);
        $url = sprintf("/api/v1/auth/policy/%s/picsure", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => SoSure::S3_BUCKET_POLICY,
            'key' => 'test/picsure-test.png',
        ]);
        $data = $this->verifyResponse(200);

        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$container, $policyData['id']);

        $this->assertEquals(PhonePolicy::PICSURE_STATUS_MANUAL, $updatedPolicy->getPicSureStatus());
    }

    public function testPicsureWithClaim()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPicsureWithClaim', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);

        $repo = static::$dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($policyData['id']);
        $claim = new Claim();
        $claim->setNumber($this->getRandomClaimNumber());
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setHandlingTeam(Claim::TEAM_DAVIES);
        $policy->addClaim($claim);
        static::$dm->persist($claim);
        static::$dm->flush();

        $url = sprintf("/api/v1/auth/policy/%s/picsure", $policyData['id']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'bucket' => SoSure::S3_BUCKET_POLICY,
            'key' => 'test/picsure-test.png',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PICSURE_DISALLOWED);
    }

    // policy/{id}/reconnect

    /**
     *
     */
    public function testApiReconnectOk()
    {
        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('testApiReconnectOkA', $this),
            'foo'
        );
        $userB = self::createUser(
            self::$userManager,
            self::generateEmail('testApiReconnectOkB', $this),
            'foo'
        );
        $cognitoIdentityIdA = $this->getAuthUser($userA);
        $cognitoIdentityIdB = $this->getAuthUser($userB);

        $lastYear = \DateTime::createFromFormat('U', time());
        $lastYear = $lastYear->sub(new \DateInterval('P350D'));
        $policyA = static::initPolicy(
            $userA,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $lastYear,
            true
        );
        $policyB = static::initPolicy(
            $userB,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $lastYear,
            true
        );
        $this->payPolicyMonthly($userA, $policyA, $lastYear);
        $this->payPolicyMonthly($userB, $policyB, $lastYear);
        $this->assertEquals(Policy::STATUS_ACTIVE, $policyA->getStatus());
        $this->assertEquals(Policy::STATUS_ACTIVE, $policyB->getStatus());

        $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyA->getId());

        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
            'email' => $userB->getEmail(),
            'name' => 'invite accept test',
        ]);
        $invitationData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityIdB, $url, [
            'action' => 'accept',
            'policy_id' => $policyB->getId()
        ]);
        $data = $this->verifyResponse(200);
        
        $renewalPolicyA = static::$policyService->createPendingRenewal(
            $policyA,
            \DateTime::createFromFormat('U', time())
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());

        $renewalPolicyB = static::$policyService->createPendingRenewal(
            $policyB,
            \DateTime::createFromFormat('U', time())
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyB->getStatus());

        $url = sprintf("/api/v1/auth/policy/%s/renew", $policyA->getId());

        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
            'number_payments' => '12',
        ]);
        $data = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/policy/%s?_method=GET", $renewalPolicyA->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
        ]);
        $data = $this->verifyResponse(200);

        $reconnectionId = $data['connections'][0]['id'];

        $url = sprintf("/api/v1/auth/policy/%s/reconnect", $renewalPolicyA->getId());

        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
            'renew' => 'foo',
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
            'connection_id' => 'foo',
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
            'renew' => 'foo',
            'connection_id' => '1',
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
            'renew' => false,
            'connection_id' => 'foo',
        ]);
        $data = $this->verifyResponse(404);

        $url = sprintf("/api/v1/auth/policy/%s/reconnect", $renewalPolicyA->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
            'renew' => false,
            'connection_id' => $reconnectionId,
        ]);
        $data = $this->verifyResponse(200);
        $this->assertFalse($data['connections'][0]['reconnect_on_renewal']);

        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
            'renew' => true,
            'connection_id' => $reconnectionId,
        ]);
        $data = $this->verifyResponse(200);
        $this->assertTrue($data['connections'][0]['reconnect_on_renewal']);
    }

    public function testApiReconnectTooMany()
    {
        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('testApiReconnectTooManyA', $this),
            'foo'
        );
        $cognitoIdentityIdA = $this->getAuthUser($userA);

        $lastYear = \DateTime::createFromFormat('U', time());
        $lastYear = $lastYear->sub(new \DateInterval('P350D'));
        $policyA = static::initPolicy(
            $userA,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $lastYear,
            true
        );
        $this->payPolicyMonthly($userA, $policyA, $lastYear);
        $this->assertEquals(Policy::STATUS_ACTIVE, $policyA->getStatus());

        $connectNo = 20;

        for ($i = 1; $i <= $connectNo; $i++) {
            $userB = self::createUser(
                self::$userManager,
                self::generateEmail(sprintf('testApiReconnectTooManyB%d', $i), $this),
                'foo'
            );
            $cognitoIdentityIdB = $this->getAuthUser($userB);
            $lastYear = \DateTime::createFromFormat('U', time());
            $lastYear = $lastYear->sub(new \DateInterval('P290D'));
            $policyB = static::initPolicy(
                $userB,
                static::$dm,
                $this->getRandomPhone(static::$dm),
                $lastYear,
                true
            );
            $this->payPolicyMonthly($userB, $policyB, $lastYear);
            //$this->assertEquals(Policy::STATUS_ACTIVE, $policyB->getStatus());

            $url = sprintf("/api/v1/auth/policy/%s/invitation?debug=true", $policyA->getId());

            $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
                'email' => $userB->getEmail(),
                'name' => 'invite accept test',
            ]);
            $invitationData = $this->verifyResponse(200);

            $url = sprintf("/api/v1/auth/invitation/%s", $invitationData['id']);
            $crawler = static::postRequest(self::$client, $cognitoIdentityIdB, $url, [
                'action' => 'accept',
                'policy_id' => $policyB->getId()
            ]);
            $data = $this->verifyResponse(200);
        }

        $renewalPolicyA = static::$policyService->createPendingRenewal(
            $policyA,
            \DateTime::createFromFormat('U', time())
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicyA->getStatus());

        $url = sprintf("/api/v1/auth/policy/%s/renew", $policyA->getId());

        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
            'number_payments' => '12',
        ]);
        $data = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/policy/%s?_method=GET", $renewalPolicyA->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
        ]);
        $policyData = $this->verifyResponse(200);

        $url = sprintf("/api/v1/auth/policy/%s/reconnect", $renewalPolicyA->getId());
        $count = 0;
        foreach ($policyData['connections'] as $connection) {
            if (!$connection['reconnect_on_renewal']) {
                $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
                    'renew' => true,
                    'connection_id' => $connection['id'],
                ]);
                $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
                $count++;
            }
        }

        $this->assertEquals($connectNo, $policyData['pot']['max_connections'] + $count);

        $url = sprintf("/api/v1/auth/policy/%s?_method=GET", $renewalPolicyA->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityIdA, $url, [
        ]);
        $data = $this->verifyResponse(200);
    }

    // policy/{id}/renew

    /**
     *
     */
    public function testApiRenew()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testApiRenew', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $lastYear = \DateTime::createFromFormat('U', time());
        $lastYear = $lastYear->sub(new \DateInterval('P350D'));
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $lastYear,
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $lastYear, true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            \DateTime::createFromFormat('U', time())
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        $url = sprintf("/api/v1/auth/policy/%s/renew", $policy->getId());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'number_payments' => '1',
            'cashback' => ['account_name' => 'foo'],
        ]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'number_payments' => '15',
            'cashback' => ['account_name' => 'foo', 'sort_code' => '123456', 'account_number' => '12345678'],
        ]);
        $data = $this->verifyResponse(500);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'number_payments' => '12',
        ]);
        $data = $this->verifyResponse(200);
    }

    public function testApiRenewCashback()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testApiRenewCashback', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $lastYear = \DateTime::createFromFormat('U', time());
        $lastYear = $lastYear->sub(new \DateInterval('P350D'));
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $lastYear,
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $lastYear, true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            \DateTime::createFromFormat('U', time())
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        $url = sprintf("/api/v1/auth/policy/%s/renew", $policy->getId());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'number_payments' => '12',
            'cashback' => ['account_name' => 'a', 'sort_code' => '123456', 'account_number' => '12345678'],
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'number_payments' => '12',
            'cashback' => ['account_name' => 'foo', 'sort_code' => '1', 'account_number' => '12345678'],
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'number_payments' => '12',
            'cashback' => ['account_name' => 'foo', 'sort_code' => '123456', 'account_number' => '1'],
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'number_payments' => '12',
            'cashback' => ['account_name' => 'foo', 'sort_code' => '123456', 'account_number' => '12345678'],
        ]);
        $data = $this->verifyResponse(200);
    }

    public function testApiRenewCashbackDeclineThenRenew()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testApiRenewCashbackDecline', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $lastYear = \DateTime::createFromFormat('U', time());
        $lastYear = $lastYear->sub(new \DateInterval('P350D'));
        $policy = static::initPolicy(
            $user,
            static::$dm,
            $this->getRandomPhone(static::$dm),
            $lastYear,
            true
        );

        $policy->setStatus(PhonePolicy::STATUS_PENDING);
        static::$policyService->setEnvironment('prod');
        static::$policyService->create($policy, $lastYear, true);
        static::$policyService->setEnvironment('test');
        static::$dm->flush();

        $this->assertEquals(Policy::STATUS_ACTIVE, $policy->getStatus());

        $renewalPolicy = static::$policyService->createPendingRenewal(
            $policy,
            \DateTime::createFromFormat('U', time())
        );
        $this->assertEquals(Policy::STATUS_PENDING_RENEWAL, $renewalPolicy->getStatus());

        $url = sprintf("/api/v1/auth/policy/%s/renew", $policy->getId());

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'number_payments' => '12',
            'cashback' => ['account_name' => 'foo', 'sort_code' => '123456', 'account_number' => '12345678'],
            'decline' => true
        ]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'number_payments' => '0',
            'cashback' => ['account_name' => 'foo', 'sort_code' => '123456', 'account_number' => '12345678'],
            'decline' => true
        ]);
        $data = $this->verifyResponse(200);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'number_payments' => '12',
            'cashback' => ['account_name' => 'foo', 'sort_code' => '123456', 'account_number' => '12345678'],
            'decline' => false
        ]);
        $data = $this->verifyResponse(200);
    }

    // scode

    /**
     *
     */
    public function testCreatePolicySCode()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-scode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $dm = $this->getDocumentManager(true);
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $policyRepo->find($policyId);
        $oldCode = $policy->getStandardSCode()->getCode();
        $policy->getStandardSCode()->setActive(false);
        $dm->flush();

        $url = sprintf('/api/v1/auth/scode');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => SCode::TYPE_STANDARD,
            'policy_id' => $policyId,
        ]);
        $getData = $this->verifyResponse(200);
        $this->assertEquals(8, mb_strlen($getData['code']));
        $this->assertGreaterThan(8, mb_strlen($getData['share_link']));
        $this->assertEquals(SCode::TYPE_STANDARD, $getData['type']);
        $this->assertNotEquals($oldCode, $getData['code']);

        $dm = $this->getDocumentManager(true);
        $scodeRepo = $dm->getRepository(SCode::class);
        $this->assertNotNull($scodeRepo->findOneBy(['code' => $getData['code']]));
    }

    public function testCreatePolicySCodeMissingParams()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-scode-notype', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $url = sprintf('/api/v1/auth/scode');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'policy_id' => $policyId,
        ]);
        $getData = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => SCode::TYPE_STANDARD,
        ]);
        $getData = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testCreatePolicySCodeDuplicate()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-scode-duptype', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $url = sprintf('/api/v1/auth/scode');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => SCode::TYPE_STANDARD,
            'policy_id' => $policyId,
        ]);
        $getData = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    // DELETE scode/{id}

    /**
     *
     */
    public function testDeletePolicySCode()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-del-scode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $dm = $this->getDocumentManager(true);
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $policyRepo->find($policyId);
        $sCode = $policy->getStandardSCode()->getCode();

        $url = sprintf('/api/v1/auth/scode/%s?_method=DELETE', $sCode);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $policyRepo->find($policyId);
        $this->assertNull($policy->getStandardSCode());
    }

    public function testDeletePolicyInactiveSCode()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-delete-policy-inactive-scode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $dm = $this->getDocumentManager(true);
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $policyRepo->find($policyId);
        $sCode = $policy->getStandardSCode();

        $sCode->setActive(false);
        $dm->flush();

        $url = sprintf('/api/v1/auth/scode/%s?_method=DELETE', $sCode->getCode());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    // PUT scode/{id}

    /**
     *
     */
    public function testPutPolicySCode()
    {
        $payerEmail = self::generateEmail('testPutPolicySCode-payer', $this);
        $payeeEmail = self::generateEmail('testPutPolicySCode-payee', $this);
        // Payer
        $payerUser = self::createUser(
            self::$userManager,
            $payerEmail,
            'foo'
        );
        $payerCognitoIdentityId = $this->getAuthUser($payerUser);
        $crawler = $this->generatePolicy($payerCognitoIdentityId, $payerUser);
        $createData = $this->verifyResponse(200);
        $payerPolicyId = $createData['id'];
        $amount = null;
        $this->payPolicy($payerUser, $payerPolicyId, $amount);
        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $payerCognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(0, count($data['multipay_policies']));
        $url = sprintf('/api/v1/auth/scode');
        $crawler = static::postRequest(self::$client, $payerCognitoIdentityId, $url, [
            'type' => SCode::TYPE_MULTIPAY,
            'policy_id' => $payerPolicyId,
        ]);
        $getData = $this->verifyResponse(200);
        $sCode = $getData['code'];
        $this->assertEquals(8, mb_strlen($sCode));
        // Payee
        $payeeUser = self::createUser(
            self::$userManager,
            $payeeEmail,
            'foo'
        );
        $payeeCognitoIdentityId = $this->getAuthUser($payeeUser);
        $crawler = $this->generatePolicy($payeeCognitoIdentityId, $payeeUser);
        $createData = $this->verifyResponse(200);
        $payeePolicyId = $createData['id'];
        // Finally scode request
        $url = sprintf('/api/v1/auth/scode/%s?_method=PUT', $sCode);
        $crawler = static::postRequest(self::$client, $payeeCognitoIdentityId, $url, [
            'action' => 'request',
            'policy_id' => $payeePolicyId,
        ]);
        $getData = $this->verifyResponse(422, ApiErrorCode::ERROR_UPGRADE_APP);
        // Verify payee data
        $dm = $this->getDocumentManager(true);
        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $payeePolicy */
        $payeePolicy = $policyRepo->find($payeePolicyId);
        $this->assertNull($payeePolicy->getStatus());
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

        $url = sprintf('/api/v1/auth/policy/%s/terms?maxPotValue=62.8&_method=GET', $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);
        $policyUrl = self::$router->generate('policy_terms', ['id' => $policyId]);
        $this->assertTrue(mb_stripos($getData["view_url"], $policyUrl) >= 0);
        $this->assertTrue(mb_stripos($getData["view_url"], 'http') >= 0);
    }

    /**
     *
     */
    public function testGetPolicyTerms2()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('policy-terms2', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $url = sprintf('/api/v1/auth/policy/v2/%s/terms?maxPotValue=62.8&_method=GET', $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $getData = $this->verifyResponse(200);
        $policyUrl = self::$router->generate('policy_terms2', ['id' => $policyId]);
        $this->assertTrue(mb_stripos($getData["view_url"], $policyUrl) >= 0);
        $this->assertTrue(mb_stripos($getData["view_url"], 'http') >= 0);
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

    public function testGetCurrentUserLocked()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testGetCurrentUserLocked', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id'], null);

        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);

        $user->setLocked(true);
        static::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(403);
    }

    public function testGetCurrentUserPromoAppMarch2017PreFeb()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testGetCurrentUserPromoAppMarch2017PreFeb', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id'], null, new \DateTime('2017-01-29'));

        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(null, $data['policies'][0]['promo_code']);
    }

    public function testGetCurrentUserPromoAppMarch2017Mar()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testGetCurrentUserPromoAppMarch2017Mar', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id'], null, new \DateTime('2017-03-01'));

        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        // its after apr now, so assume no promo
        $this->assertNotEquals(Policy::PROMO_APP_MARCH_2017, $data['policies'][0]['promo_code']);
        /*
        $this->assertEquals('so-sure Rewards', $data['policies'][0]['connections'][0]['name']);
        $this->assertEquals(5, $data['policies'][0]['connections'][0]['value']);
        $this->assertEquals(5, $data['policies'][0]['pot']['value']);
        */

        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(0, $data['policies'][0]['pot']['value']);
    }

    public function testGetCurrentUserPromoAppMarch2017Apr()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testGetCurrentUserPromoAppMarch2017Apr', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id'], null, new \DateTime('2017-04-01'));

        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(null, $data['policies'][0]['promo_code']);
    }

    public function testGetCurrentUserWithCancelledUserDeclinedPolicy()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('get-user-declined', $this, true),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);

        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(1, count($data['policies']));
        $this->assertFalse($data['has_cancelled_policy']);
        $this->assertTrue($data['has_valid_policy']);
        $this->assertTrue($data['can_purchase_policy']);
        $this->assertEquals('Please update your card', $data['card_details']);
        $this->assertNull($data['payment_method']);

        /** @var PolicyService $policyService */
        $policyService = self::getContainer(true)->get('app.policy');
        $updatedPolicy = $this->assertPolicyByIdExists(self::getContainer(true), $policyData['id']);
        $policyService->cancel($updatedPolicy, SalvaPhonePolicy::CANCELLED_ACTUAL_FRAUD);

        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(1, count($data['policies']));
        $this->assertTrue($data['has_cancelled_policy']);
        $this->assertFalse($data['has_valid_policy']);
        $this->assertFalse($data['can_purchase_policy']);
    }

    public function testGetCurrentUserWithCancelledUserOkPolicy()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('get-user-ok', $this, true),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user, true);
        $policyData = $this->verifyResponse(200);

        $this->payPolicy($user, $policyData['id']);

        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(1, count($data['policies']));
        $this->assertFalse($data['has_cancelled_policy']);
        $this->assertTrue($data['has_valid_policy']);

        /** @var PolicyService $policyService */
        $policyService = self::getContainer(true)->get('app.policy');
        $policyService->setDispatcher(null);
        /** @var SalvaPhonePolicy $policy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::getContainer(true), $policyData['id']);
        $policyService->cancel($updatedPolicy, SalvaPhonePolicy::CANCELLED_COOLOFF);

        $url = sprintf('/api/v1/auth/user?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(1, count($data['policies']));
        $this->assertFalse($data['has_cancelled_policy']);
        $this->assertFalse($data['has_valid_policy']);
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
        $this->assertNull($data['has_mobile_number_verified']);
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
        $birthday = new \DateTime('1980-01-01');
        $data = [
            'first_name' => 'bar',
            'last_name' => 'foo',
            'email' => 'barfoo@auth-api.so-sure.com',
            'mobile_number' => '+447700900000',
            'facebook_id' => 'abcd',
            'facebook_access_token' => 'zy',
            'birthday' => $birthday->format(\DateTime::ATOM),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals('barfoo@auth-api.so-sure.com', $result['email']);
        $this->assertEquals('bar', $result['first_name']);
        $this->assertEquals('foo', $result['last_name']);
        $this->assertEquals('+447700900000', $result['mobile_number']);
        $this->assertEquals('abcd', $result['facebook_id']);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($result['id']);
        $this->assertEquals($birthday, $user->getBirthday());
    }

    public function testUpdateUserBadName()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $birthday = new \DateTime('1980-01-01');
        $data = [
            'first_name' => 'bar$',
            'last_name' => 'foo$',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals('bar', $result['first_name']);
        $this->assertEquals('foo', $result['last_name']);
    }

    public function testUpdateFacebook()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'first_name' => 'bar',
            'last_name' => 'foo',
            'email' => self::$testUser3->getEmail(),
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

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('user-facebook', $this),
            'foo',
            null,
            static::$dm
        );
        $user->setFacebookId('facebook1234');
        //$dm = $this->getDocumentManager(true);
        static::$dm->flush();

        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, [
            'facebook_id' => 'facebook1234',
            'facebook_access_token' => 'lala'
        ]);
        $result = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_EXISTS);
    }

    public function testUpdateUserValidation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'first_name' => ['$ne' => 'bar']
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
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

    public function testUpdateUserEmailExists()
    {
        $originalUser = self::createUser(
            self::$userManager,
            self::generateEmail('update-user-email-exists', $this),
            'foo'
        );

        $user = self::createUser(
            self::$userManager,
            self::generateEmail('update-user-email-new', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $data = [
            'email' => self::generateEmail('update-user-email-exists', $this),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_EXISTS);
    }

    public function testUpdateUserInvalidBirthday()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'birthday' => 'abc',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUpdateUserTooOld()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'birthday' => '1800-01-01T00:00:00Z',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUpdateUserTooYoung()
    {
        $now = \DateTime::createFromFormat('U', time());
        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $data = [
            'birthday' => sprintf('%d-01-01T00:00:00Z', $now->format('Y')),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_TOO_YOUNG);
    }

    public function testUpdateUserSCode()
    {
        $dm = $this->getDocumentManager(true);
        $scode = new SCode();
        $dm->persist($scode);
        $dm->flush();

        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $birthday = new \DateTime('1980-01-01');
        $data = [
            'scode' => $scode->getCode(),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($result['id']);
        $this->assertEquals($scode->getId(), $user->getAcceptedSCode()->getId());
    }

    public function testUpdateUserInactiveSCode()
    {
        $dm = $this->getDocumentManager(true);
        $scode = new SCode();
        $scode->setActive(false);
        $dm->persist($scode);
        $dm->flush();

        $cognitoIdentityId = $this->getAuthUser(self::$testUser3);
        $url = sprintf('/api/v1/auth/user/%s', self::$testUser3->getId());
        $birthday = new \DateTime('1980-01-01');
        $data = [
            'scode' => $scode->getCode(),
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testUpdateUserSnsEndpoint()
    {
        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-a', $this),
            'foo'
        );
        $userA->setSnsEndpoint('foo');

        $userB = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-b', $this),
            'foo'
        );
        $userB->setSnsEndpoint('foo');

        $userC = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-c', $this),
            'foo'
        );

        $dm = $this->getDocumentManager(true);
        $dm->flush();

        $cognitoIdentityId = $this->getAuthUser($userC);
        $url = sprintf('/api/v1/auth/user/%s', $userC->getId());
        $data = [
            'sns_endpoint' => 'foo',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);

        /** @var User $changedUserA */
        $changedUserA = $userRepo->findOneBy(['email' => $userA->getEmail()]);
        /** @var User $changedUserB */
        $changedUserB = $userRepo->findOneBy(['email' => $userB->getEmail()]);
        /** @var User $changedUserC */
        $changedUserC = $userRepo->findOneBy(['email' => $userC->getEmail()]);
        $this->assertEquals('foo', $changedUserC->getSnsEndpoint());
        $this->assertNull($changedUserA->getSnsEndpoint());
        $this->assertNull($changedUserB->getSnsEndpoint());

        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $userC->getId());
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, []);
        $result = $this->verifyResponse(200);
        $this->assertEquals('foo', $result['sns_endpoint']);
    }

    public function testUpdateUserChangeNameWithPolicy()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('update-user-change-name', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $data = [
            'first_name' => 'first',
            'last_name' => 'last',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals('first', $result['first_name']);
        $this->assertEquals('last', $result['last_name']);

        // note this changes the user's name
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $this->payPolicy($user, $policyId);

        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($user->getId());
        $this->assertTrue($user->hasActivePolicy());

        $url = sprintf('/api/v1/auth/user/%s', $user->getId());
        $data = [
            'first_name' => 'newfirst',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $data = [
            'last_name' => 'newlast',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUpdateUserDupUserWithoutChange()
    {
        $mobile = self::generateRandomMobile();
        $facebookId = rand(1, 999999);
        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('user-dup-a', $this),
            'foo'
        );
        $userA->setMobileNumber($mobile);

        $userB = self::createUser(
            self::$userManager,
            self::generateEmail('user-dup-b', $this),
            'foo'
        );
        $userB->setMobileNumber($mobile);

        $dm = $this->getDocumentManager(true);
        $dm->persist($userA);
        $dm->persist($userB);
        $dm->flush();

        // Attempting to update fields that are the same.  As they're not changing
        // the dup user detection should not trigger
        $cognitoIdentityId = $this->getAuthUser($userB);
        $url = sprintf('/api/v1/auth/user/%s', $userB->getId());
        $data = [
            'mobile_number' => $mobile,
            'facebook_id' => $facebookId,
            'facebook_access_token' => 'zy',
        ];
        $crawler = static::putRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
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
        $this->assertEquals('BX1 1LT', $result['addresses'][0]['postcode']);

        $this->assertFalse(isset($data['intercom_token']));
        $this->assertFalse(isset($data['cognito_token']));
        $this->assertFalse(isset($data['user_token']));
    }

    public function testUserAddAddressBadLines()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1$',
            'line2' => 'address line 2$',
            'line3' => 'address line 3$',
            'city' => 'London$',
            'postcode' => 'BX11LT',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals(self::$testUser->getEmailCanonical(), $result['email']);
        $this->assertTrue(isset($result['addresses']));
        $this->assertTrue(isset($result['addresses'][0]));
        $this->assertEquals($data['type'], $result['addresses'][0]['type']);
        $this->assertEquals('address line 1', $result['addresses'][0]['line1']);
        $this->assertEquals('address line 2', $result['addresses'][0]['line2']);
        $this->assertEquals('address line 3', $result['addresses'][0]['line3']);
        $this->assertEquals('London', $result['addresses'][0]['city']);
        $this->assertEquals('BX1 1LT', $result['addresses'][0]['postcode']);
    }

    public function testUserAddressValidation()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/address', self::$testUser->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'line2' => ['$ne' => '1'],
            'city' => 'London',
            'postcode' => 'BX11LT',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
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

    // user/{id}/payment

    /**
     *
     */
    public function testUpdateUserPaymentJudopayOk()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdateUserPaymentJudopayOk', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $details = $judopay->testRegisterDetails(
            $user,
            rand(1, 999999),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];
        $this->payPolicy($user, $policyId);

        $url = sprintf("/api/v1/auth/user/%s/payment", $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => $details['consumer']['consumerToken'],
            'card_token' => $details['cardDetails']['cardToken'],
            'receipt_id' => $details['receiptId'],
        ]]);
        $data = $this->verifyResponse(200);

        $url = sprintf('/api/v1/auth/user/%s?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(self::$JUDO_TEST_CARD_NAME, $data['card_details']);
        $this->assertEquals('judo', $data['payment_method']);

        $details = $judopay->testRegisterDetails(
            $user,
            rand(1, 999999),
            self::$JUDO_TEST_CARD2_NUM,
            self::$JUDO_TEST_CARD2_EXP,
            self::$JUDO_TEST_CARD2_PIN
        );

        $url = sprintf("/api/v1/auth/user/%s/payment", $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => $details['consumer']['consumerToken'],
            'card_token' => $details['cardDetails']['cardToken'],
            'receipt_id' => $details['receiptId'],
        ]]);
        $data = $this->verifyResponse(200);
    }

    public function testUpdateUserPaymentJudopayFail()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdateUserPaymentJudopayFail', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $details = $judopay->testRegisterDetails(
            $user,
            rand(1, 999999),
            '4221 6900 0000 4963',
            '12/20',
            '125'
        );

        $url = sprintf("/api/v1/auth/user/%s/payment", $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => $details['consumer']['consumerToken'],
            'card_token' => 'unknown',
            'receipt_id' => $details['receiptId'],
        ]]);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED);
    }

    public function testUpdateUserPaymentJudopayNoData()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdateUserPaymentJudopayNoData', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $url = sprintf("/api/v1/auth/user/%s/payment", $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
        ]]);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testUpdateUserPaymentJudopayDiffUser()
    {
        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdateUserPaymentJudopayDiffUser-A', $this),
            'foo'
        );
        $userB = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdateUserPaymentJudopayDiffUser-B', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($userA);

        /** @var JudopayService $judopay */
        $judopay = $this->getContainer(true)->get('app.judopay');
        $details = $judopay->testRegisterDetails(
            $userA,
            rand(1, 999999),
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );

        $url = sprintf("/api/v1/auth/user/%s/payment", $userB->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => $details['consumer']['consumerToken'],
            'card_token' => $details['cardDetails']['cardToken'],
            'receipt_id' => $details['receiptId'],
        ]]);
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_ACCESS_DENIED);
    }

    public function testUpdateUserPaymentJudopayUnknownUser()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUpdateUserPaymentJudopayUnknownUser', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $url = sprintf("/api/v1/auth/user/%s/payment", 'foo');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
            'consumer_token' => 'foo',
            'card_token' => 'foo',
            'receipt_id' => 'foo',
        ]]);
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    // GET /user/{id}/quote

    /**
     *
     */
    public function testUserQuote()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUserQuote', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->updateUserDetails($cognitoIdentityId, $user);

        $url = sprintf(
            '/api/v1/auth/user/%s/quote?_method=GET&%s',
            $user->getId(),
            'make=Apple&device=iPhone%206&rooted=false'
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertTrue(count($data['quotes']) > 1);
        // Make sure we're not returning all the quotes
        $this->assertTrue(count($data['quotes']) < 10);

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

    public function testUserQuoteInvalidUser()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUserQuoteInvalidUser', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->updateUserDetails($cognitoIdentityId, $user);

        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf(
            '/api/v1/auth/user/%s/quote?_method=GET&%s',
            $user->getId(),
            'make=Apple&device=iPhone%206&rooted=false'
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(403);
    }

    public function testUserQuoteYearlyBilling()
    {
        self::populateYearlyPostcodes();
        
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUserQuoteYearlyBilling', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->updateUserDetails($cognitoIdentityId, $user);

        $url = sprintf('/api/v1/auth/user/%s/address', $user->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => SoSure::$yearlyOnlyPostcodes[0]
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->verifyResponse(200);

        $url = sprintf(
            '/api/v1/auth/user/%s/quote?_method=GET&%s',
            $user->getId(),
            'make=Apple&device=iPhone%206&rooted=false'
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertTrue(count($data['quotes']) > 1);
        $this->assertNull($data['quotes'][0]['monthly_premium']);
        $this->assertGreaterThan(0, $data['quotes'][0]['yearly_premium']);
    }

    public function testUserQuoteHasPolicy()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUserQuoteHasPolicy', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $this->updateUserDetails($cognitoIdentityId, $user);

        $url = sprintf('/api/v1/auth/user/%s/address', $user->getId());
        $data = [
            'type' => 'billing',
            'line1' => 'address line 1',
            'city' => 'London',
            'postcode' => 'BX11LT'
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $this->verifyResponse(200);

        $url = sprintf(
            '/api/v1/auth/user/%s/quote?_method=GET&%s',
            $user->getId(),
            'make=Apple&device=iPhone%204&rooted=false'
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_QUOTE_EXPIRED);

        $url = sprintf(
            '/api/v1/auth/user/%s/quote?_method=GET&%s',
            $user->getId(),
            'make=Apple&device=iPhone%204&rooted=false&has_policy=true'
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_QUOTE_EXPIRED);

        $policy = new PhonePolicy();
        $policy->setPhone(static::$expiredPhone);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $user->addPolicy($policy);
        static::$dm->persist($policy);
        static::$dm->flush();

        $url = sprintf(
            '/api/v1/auth/user/%s/quote?_method=GET&%s',
            $user->getId(),
            'make=Apple&device=iPhone%204&rooted=false&has_policy=true'
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
        $this->assertEquals(true, $data['device_found']);
        $this->assertTrue(count($data['quotes']) > 1);
        $this->assertGreaterThan(0, $data['quotes'][0]['monthly_premium']);
        $this->assertGreaterThan(0, $data['quotes'][0]['yearly_premium']);
    }

    public function testAuthNoActualSecurityInteractiveLoginEvent()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testAuthNoSecurityInteractiveLoginEvent', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);

        $url = '/api/v1/auth/ping?_method=GET';
        self::$client->enableProfiler();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);

        if (!self::$client->getProfile()) {
            throw new \Exception('Profiler must be enabled');
        }
        if (self::$client->getProfile()) {
            /** @var EventDataCollector $eventDataCollector */
            $eventDataCollector = self::$client->getProfile()->getCollector('events');
            $listeners = $eventDataCollector->getCalledListeners();
            // @codingStandardsIgnoreStart
            $this->assertTrue(isset($listeners['security.interactive_login.AppBundle\Listener\SecurityListener::onSecurityInteractiveLogin']));
            $this->assertFalse(isset($listeners['security.interactive_login.actual.AppBundle\Listener\SecurityListener::onActualSecurityInteractiveLogin']));
            // @codingStandardsIgnoreEnd
        }
    }
    // policy/{id}/track/location

    /**
     *
     */
    public function testPolicyTrackLocation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPolicyTrackLocation', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $url = sprintf('/api/v1/auth/policy/%s/track/location', $policyId);
        $data = [
            'type' => 'picsure',
            'latitude' => '51.524490',
            'longitude' => '-0.087658',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $policyRepo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $policyRepo->find($policyId);
        $locations = $policy->getPicsureLocations();
        $this->assertEquals(1, count($locations));
        $this->assertEquals([-0.087658, 51.524490], $locations[0]->getCoordinates());
    }

    public function testPolicyTrackLocationUnknownId()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/policy/1/track/location');
        $data = [
            'type' => 'picsure',
            'latitude' => '51.524490',
            'longitude' => '-0.087658',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(404);
    }

    public function testPolicyTrackLocationValidation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPolicyrTrackLocationValidation', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $createData = $this->verifyResponse(200);
        $policyId = $createData['id'];

        $url = sprintf('/api/v1/auth/policy/%s/track/location', $policyId);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $data = [
            'type' => 'picsure',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $data = [
            'type' => 'picsure',
            'latitude' => '51.524490',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $data = [
            'type' => 'picsure',
            'longitude' => '51.524490',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);

        $data = [
            'type' => 'bla',
            'latitude' => '51.524490',
            'longitude' => '-0.087658',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $data = [
            'type' => 'picsure',
            'latitude' => '151.524490',
            'longitude' => '-0.087658',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);

        $data = [
            'type' => 'picsure',
            'latitude' => '51.524490',
            'longitude' => '-200.087658',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testPolicyTrackLocationDifferentUser()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testPolicyTrackLocationDifferentUser', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $crawler = $this->generatePolicy($cognitoIdentityId, $user);
        $policyData = $this->verifyResponse(200);

        $user = static::createUser(self::$userManager, 'policytracklocation-unauth@auth-api.so-sure.com', 'foo');
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/auth/policy/%s/track/location', $policyData['id']);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'type' => 'picsure',
            'latitude' => '51.524490',
            'longitude' => '-0.087658',
        ]);
        $data = $this->verifyResponse(403);
    }

    // GET user/{id}/verify/mobilenumber

    /**
     *
     */
    public function testUserRequestVerificationMobileNumber()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUserRequestVerificationMobileNumber', $this),
            'foo',
            true
        );

        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user/%s/verify/mobilenumber?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200);
    }

    public function testUserRequestVerificationMobileNumberUnknownId()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/1/verify/mobilenumber?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(404);
    }

    public function testUserRequestVerificationMobileNumberValidation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUserRequestVerificationMobileNumberValidation', $this),
            'foo'
        );

        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user/%s/verify/mobilenumber?_method=GET', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_INVALD_DATA_FORMAT);
    }

    public function testUserRequestVerificationMobileNumberDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/verify/mobilenumber?_method=GET', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(403);
    }

    // POST user/{id}/verify/mobilenumber

    /**
     *
     */
    public function testUserVerifyMobileNumber()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUserVerifyMobileNumber', $this),
            'foo',
            true
        );

        $redis = $this->getRedis();
        $key = sprintf('Mobile:Validation:%s:%s', $user->getId(), '123456');
        $redis->setex($key, 600000, '123456');
        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user/%s/verify/mobilenumber', $user->getId());
        $data = [
            'code' => '123456',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $result = $this->verifyResponse(200);
        $this->assertEquals($user->getEmailCanonical(), $result['email']);
        $this->assertTrue($result['has_mobile_number_verified']);
        $updatedUser = $this->assertUserExists(self::$client->getContainer(), $user);
        $this->assertTrue($updatedUser->getMobileNumberVerified());
    }

    public function testUserVerifyMobileNumberUnknownId()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/1/verify/mobilenumber');
        $data = [
            'code' => '123456',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(404);
    }

    public function testUserVerifyMobileNumberValidation()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testUserVerifyMobileNumberValidation', $this),
            'foo',
            true
        );

        $redis = $this->getRedis();
        $key = sprintf('Mobile:Validation:%s:%s', $user->getId(), '123456');
        $redis->setex($key, 600000, '123456');

        $cognitoIdentityId = $this->getAuthUser($user);
        $url = sprintf('/api/v1/auth/user/%s/verify/mobilenumber', $user->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
        $data = [
            'foo' => '123456',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
        $data = [
            'code' => '123',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_VERIFY_CODE);
        $data = [
            'code' => '654321',
        ];
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, $data);
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_VERIFY_CODE);
    }

    public function testUserVerifyMobileNumberDifferentUser()
    {
        $cognitoIdentityId = $this->getAuthUser(self::$testUser);
        $url = sprintf('/api/v1/auth/user/%s/verify/mobilenumber', self::$testUser2->getId());
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, [
            'code' => '123456',
        ]);
        $data = $this->verifyResponse(403);
    }

    /**
     * @return Client
     */
    private function getRedis()
    {
        /** @var Client $redis */
        $redis = $this->getContainer(true)->get('snc_redis.default');

        return $redis;
    }
}
