<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-net
 */
class ApiKeyControllerTest extends BaseControllerTest
{
    // quote

    /**
     *
     */
    public function testQuoteUnknown()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = self::$client->request('GET', '/api/v1/key/quote');
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testQuoteMake()
    {
        $crawler = self::$client->request('GET', '/api/v1/key/quote?make=Apple');
        $data = $this->verifyResponse(200);

        $this->assertGreaterThan(5, count($data['quotes']));
    }

    public function testQuoteMakeModel()
    {
        $crawler = self::$client->request('GET', '/api/v1/key/quote?make=Apple&model=iPhone%207');
        $data = $this->verifyResponse(200);

        $this->assertGreaterThan(1, count($data['quotes']));
        $this->assertLessThan(5, count($data['quotes']));
    }
}
