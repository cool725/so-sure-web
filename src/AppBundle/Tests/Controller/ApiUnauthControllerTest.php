<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-net
 */
class ApiUnauthControllerTest extends BaseApiControllerTest
{
    // token unauth

    /**
     *
     */
    public function testTokenUnauthOk()
    {
        $this->clearRateLimit();
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('unauth-token', $this), 'bar');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', array(
            'token' => $user->getToken(),
            'cognito_id' => self::$identity->getId(),
        ));
        $data = $this->verifyResponse(200);
        $this->assertTrue(mb_strlen($data['token']) > 20);
    }

    public function testTokenUnauthOkNoVersionRecordMobileIdentifier()
    {
        $this->clearRateLimit();
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(
            self::$userManager,
            static::generateEmail('testTokenUnauthOkNoVersionRecordMobileIdentifier', $this),
            'bar'
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', array(
            'token' => $user->getToken(),
            'cognito_id' => self::$identity->getId(),
        ));
        $data = $this->verifyResponse(200);
        $this->assertTrue(mb_strlen($data['token']) > 20);

        $dm = $this->getDocumentManager(true);
        $repo = $dm->getRepository(User::class);
        /** @var User $updatedUser */
        $updatedUser = $repo->find($user->getId());
        $identityLog = $updatedUser->getLatestMobileIdentityLog();

        $this->assertEquals(null, $identityLog->getPlatform());
        $this->assertEquals(null, $identityLog->getVersion());
        $this->assertEquals(null, $identityLog->getUuid());
        $this->assertNull($identityLog->getPhone());
    }

    public function testTokenUnauthOkVersionRecordMobileIdentifier()
    {
        $this->clearRateLimit();
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = '/api/v1/version?platform=ios&version=2.0.1&device=iPhone%206s&memory=64&uuid=1&_method=GET';
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, []);
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);

        $user = static::createUser(
            self::$userManager,
            static::generateEmail('testTokenUnauthOkVersionRecordMobileIdentifier', $this),
            'bar'
        );
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', array(
            'token' => $user->getToken(),
            'cognito_id' => self::$identity->getId(),
        ));
        $data = $this->verifyResponse(200);
        $this->assertTrue(mb_strlen($data['token']) > 20);

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

    public function testTokenUnauthBad()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('unauth-badtoken', $this), 'bar');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', array(
            'token' => sprintf('%s-bad', $user->getToken()),
            'cognito_id' => self::$identity->getId(),
        ));
        $data = $this->verifyResponse(403);
    }

    public function testTokenUnauthMissing()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', []);
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testTokenUnauthOkUserDisabled()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('unauth-token3', $this), 'bar');

        $user->setEnabled(false);
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', array(
            'token' => $user->getToken(),
            'cognito_id' => self::$identity->getId(),
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_RESET_PASSWORD);
    }

    public function testTokenUnauthOkUserLocked()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('unauth-token4', $this), 'bar');

        $user->setLocked(true);
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', array(
            'token' => $user->getToken(),
            'cognito_id' => self::$identity->getId(),
        ));
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_USER_SUSPENDED);
    }

    public function testTokenUnauthRateLimited()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('unauth-ratelimit', $this), 'bar');

        for ($i = 1; $i <= RateLimitService::$maxIpRequests[RateLimitService::DEVICE_TYPE_TOKEN] + 1; $i++) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', [
                'token' => $user->getToken(),
                'cognito_id' => self::$identity->getId(),
            ]);
        }
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_TOO_MANY_REQUESTS);
    }
}
