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

    // gocompare

    /**
     *
     */
    public function testGoCompare()
    {
        $data = '{"request": {
  "gadgets" : [
    {"gadget": {"gadget_id": 20, "loss_cover": true}},
    {"gadget": {"gadget_id": 21, "loss_cover": true}},
    {"gadget": {"gadget_id": 22, "loss_cover": true}},
    {"gadget": {"gadget_id": 23, "loss_cover": true}},
    {"gadget": {"gadget_id": 24, "loss_cover": true}},
    {"gadget": {"gadget_id": 25, "loss_cover": true}},
    {"gadget": {"gadget_id": 26, "loss_cover": true}},
    {"gadget": {"gadget_id": 27, "loss_cover": true}},
    {"gadget": {"gadget_id": 28, "loss_cover": true}},
    {"gadget": {"gadget_id": 29, "loss_cover": true}},
    {"gadget": {"gadget_id": 30, "loss_cover": true}},
    {"gadget": {"gadget_id": 31, "loss_cover": true}},
    {"gadget": {"gadget_id": 32, "loss_cover": true}},
    {"gadget": {"gadget_id": 33, "loss_cover": true}},
    {"gadget": {"gadget_id": 34, "loss_cover": true}},
    {"gadget": {"gadget_id": 35, "loss_cover": true}},
    {"gadget": {"gadget_id": 36, "loss_cover": true}},
    {"gadget": {"gadget_id": 37, "loss_cover": true}},
    {"gadget": {"gadget_id": 38, "loss_cover": true}},
    {"gadget": {"gadget_id": 39, "loss_cover": true}},
    {"gadget": {"gadget_id": 40, "loss_cover": true}},
    {"gadget": {"gadget_id": 41, "loss_cover": true}},

    {"gadget": {"gadget_id": 215, "loss_cover": true}},
    {"gadget": {"gadget_id": 216, "loss_cover": true}},
    {"gadget": {"gadget_id": 217, "loss_cover": true}},
    {"gadget": {"gadget_id": 218, "loss_cover": true}},
    {"gadget": {"gadget_id": 219, "loss_cover": true}},
    {"gadget": {"gadget_id": 220, "loss_cover": true}},
    {"gadget": {"gadget_id": 221, "loss_cover": true}},
    {"gadget": {"gadget_id": 222, "loss_cover": true}},
    {"gadget": {"gadget_id": 223, "loss_cover": true}},
    {"gadget": {"gadget_id": 224, "loss_cover": true}},

    {"gadget": {"gadget_id": 806, "loss_cover": true}},
    {"gadget": {"gadget_id": 807, "loss_cover": true}},
    {"gadget": {"gadget_id": 808, "loss_cover": true}},
    {"gadget": {"gadget_id": 809, "loss_cover": true}},

    {"gadget": {"gadget_id": 814, "loss_cover": true}},
    {"gadget": {"gadget_id": 815, "loss_cover": true}},
    {"gadget": {"gadget_id": 816, "loss_cover": true}},
    {"gadget": {"gadget_id": 817, "loss_cover": true}},
    {"gadget": {"gadget_id": 818, "loss_cover": true}},
    {"gadget": {"gadget_id": 819, "loss_cover": true}},
    {"gadget": {"gadget_id": 820, "loss_cover": true}},

    {"gadget": {"gadget_id": 835, "loss_cover": true}},
    {"gadget": {"gadget_id": 836, "loss_cover": true}}
  ]
}}';
        $url = sprintf(
            '/api/v1/key/gocompare'
        );

        $crawler =  static::$client->request(
            "POST",
            $url,
            array(),
            array(),
            array(
                'CONTENT_TYPE' => 'application/json'
            ),
            $data
        );
        $data = $this->verifyResponse(200);
        $this->assertEquals(45, count($data['response']), json_encode($data));
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
