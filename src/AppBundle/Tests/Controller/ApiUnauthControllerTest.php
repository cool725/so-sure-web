<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-net
 */
class ApiUnauthControllerTest extends BaseControllerTest
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
        $this->assertTrue(strlen($data['token']) > 20);
    }

    public function testTokenUnauthBad()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('unauth-badtoken', $this), 'bar');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', array(
            'token' => $user->getToken() + 'bad',
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

    public function testTokenUnauthOkUserExpired()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $user = static::createUser(self::$userManager, static::generateEmail('unauth-token2', $this), 'bar');

        $user->setExpired(true);
        self::$dm->flush();

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', array(
            'token' => $user->getToken(),
            'cognito_id' => self::$identity->getId(),
        ));
        $data = $this->verifyResponse(403, ApiErrorCode::ERROR_USER_ABSENT);
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

        for ($i = 1; $i <= RateLimitService::$maxRequests[RateLimitService::DEVICE_TYPE_TOKEN] + 1; $i++) {
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/unauth/token', [
                'token' => $user->getToken(),
                'cognito_id' => self::$identity->getId(),
            ]);
        }
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_TOO_MANY_REQUESTS);
    }

    // token unauth

    /**
     *
     */
    public function testZendeskOk()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk', $this), 'bar');
        $url = sprintf(
            '/api/v1/unauth/zendesk?zendesk_key=%s&debug=true',
            static::$container->getParameter('zendesk_key')
        );
        $crawler =  static::$client->request(
            "POST",
            $url,
            ['user_token' => $user->getId()]
        );
        
        $data = $this->verifyResponse(200);
        $this->assertTrue(strlen($data['jwt']) > 20);
    }

    public function testZendeskUserNotFound()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk-notfound', $this), 'bar');
        $url = sprintf(
            '/api/v1/unauth/zendesk?zendesk_key=%s&debug=true',
            static::$container->getParameter('zendesk_key')
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            ['user_token' => '12']
        );

        $data = $this->verifyResponse(401, ApiErrorCode::ERROR_NOT_FOUND);
    }

    /*
    public function testZendeskInvalidIp()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk-invalidip', $this), 'bar');

        $crawler =  static::$client->request(
            "POST",
            sprintf('/api/v1/unauth/zendesk?zendesk_key=%s', static::$container->getParameter('zendesk_key')),
            ['user_token' => $user->getId()]
        );

        $data = $this->verifyResponse(401, ApiErrorCode::ERROR_ACCESS_DENIED);
    }
    */

    /**
     *
     */
    public function testZendeskMissingUserToken()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk-missingtoken', $this), 'bar');
        $url = sprintf(
            '/api/v1/unauth/zendesk?zendesk_key=%s&debug=true',
            static::$container->getParameter('zendesk_key')
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            []
        );

        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testZendeskInvalidToken()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk-token', $this), 'bar');

        $crawler =  static::$client->request(
            "POST",
            '/api/v1/unauth/zendesk',
            ['user_token' => $user->getId()]
        );

        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }
}
