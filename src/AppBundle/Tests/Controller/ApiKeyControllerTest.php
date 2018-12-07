<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\MultiPay;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;

/**
 * @group functional-net
 */
class ApiKeyControllerTest extends BaseApiControllerTest
{
    // quote

    /**
     *
     */
    public function testQuoteKeyAll()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = self::$client->request('GET', '/api/v1/key/quote');
        $data = $this->verifyResponse(200);
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

    // monitor

    /**
     *
     */
    public function testMonitor()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = self::$client->request('GET', '/api/v1/key/monitor/multipay');
        $data = $this->verifyResponse(200, ApiErrorCode::SUCCESS);

        $user = new User();
        $user->setEmail(static::generateEmail('testMonitor', $this));
        $policy = new SalvaPhonePolicy();
        $policy->setStatus(Policy::STATUS_MULTIPAY_REQUESTED);
        $multipay = new MultiPay();
        $multipay->setStatus(MultiPay::STATUS_ACCEPTED);
        $multipay->setPolicy($policy);
        $user->addMultiPay($multipay);
        $user->addPolicy($policy);
        self::$dm->persist($user);
        self::$dm->persist($policy);
        self::$dm->flush();

        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = self::$client->request('GET', '/api/v1/key/monitor/multipay');
        $data = $this->verifyResponse(422, ApiErrorCode::ERROR_UNKNOWN);
    }

    public function testMonitorUnknown()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = self::$client->request('GET', '/api/v1/key/monitor/policyImeiUpdatedFromClaimFoo');
        $data = $this->verifyResponse(500, ApiErrorCode::ERROR_UNKNOWN);
    }
}
