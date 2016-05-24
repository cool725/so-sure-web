<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-net
 */
class ApiExternalControllerTest extends BaseControllerTest
{
    // zendesk

    /**
     *
     */
    public function testZendeskOk()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk', $this), 'bar');
        $url = sprintf(
            '/external/zendesk?zendesk_key=%s&debug=true',
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
            '/external/zendesk?zendesk_key=%s&debug=true',
            static::$container->getParameter('zendesk_key')
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            ['user_token' => '12']
        );

        $data = $this->verifyResponse(401, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testZendeskInvalidIp()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk-invalidip', $this), 'bar');
        $url = sprintf(
            '/external/zendesk?zendesk_key=%s',
            static::$container->getParameter('zendesk_key')
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            ['user_token' => $user->getId()]
        );

        $data = $this->verifyResponse(401, ApiErrorCode::ERROR_ACCESS_DENIED);
    }

    public function testZendeskMissingUserToken()
    {
        $this->clearRateLimit();
        $user = static::createUser(self::$userManager, static::generateEmail('zendesk-missingtoken', $this), 'bar');
        $url = sprintf(
            '/external/zendesk?zendesk_key=%s&debug=true',
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
        $url = sprintf(
            '/external/zendesk?&debug=true'
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            ['user_token' => $user->getId()]
        );

        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }
}
