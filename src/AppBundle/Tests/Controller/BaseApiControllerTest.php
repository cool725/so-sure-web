<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\PostcodeTrait;
use AppBundle\Service\JudopayService;
use CensusBundle\Document\Postcode;
use Doctrine\ODM\MongoDB\DocumentManager;
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
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Listener\UserListener;
use AppBundle\Service\RateLimitService;
use AppBundle\Service\ReceperioService;
use AppBundle\Service\PCAService;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Classes\SoSure;
use AppBundle\Document\Payment\PolicyDiscountPayment;

class BaseApiControllerTest extends BaseControllerTest
{
    use PostcodeTrait;

    /** @var DocumentManager */
    protected static $censusDm;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        /** @var DocumentManager */
        $censusDm = self::$container->get('doctrine_mongodb.odm.census_document_manager');
        self::$censusDm = $censusDm;
    }

    /**
     *
     */
    protected function generatePolicy($cognitoIdentityId, $user, $clearRateLimit = true, $name = null, $phone = null)
    {
        if ($user) {
            $this->updateUserDetails($cognitoIdentityId, $user);
        }

        if ($clearRateLimit) {
            $this->clearRateLimit();
        }
        $phonePolicy = [
            'imei' => self::generateRandomImei(),
            'make' => 'OnePlus',
            'device' => 'A0001',
            'serial_number' => 'foo',
            'memory' => 63,
            'rooted' => false,
        ];
        $phonePolicy['validation_data'] = $this->getValidationData($cognitoIdentityId, [
            'imei' => $phonePolicy['imei']
        ]);
        if ($phone) {
            $phonePolicy['make'] = $phone->getMake();
            $phonePolicy['device'] = $phone->getDevices()[0];
            $phonePolicy['memory'] = $phone->getMemory();
        }
        if ($name) {
            $phonePolicy['name'] = $name;
        }

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
            'phone_policy' => $phonePolicy
        ]);

        // retry with a new imei if failing
        if ($this->getClientResponseStatusCode() != 200) {
            $phonePolicy['imei'] = self::generateRandomImei();
            $phonePolicy['validation_data'] = $this->getValidationData($cognitoIdentityId, [
                'imei' => $phonePolicy['imei']
            ]);
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/auth/policy', [
                'phone_policy' => $phonePolicy
            ]);
        }

        $this->verifyResponse(200);

        return $crawler;
    }

    protected function updateUserDetails($cognitoIdentityId, $user)
    {
        $userUpdateUrl = sprintf('/api/v1/auth/user/%s', $user->getId());
        $birthday = new \DateTime('1980-01-01');
        static::putRequest(self::$client, $cognitoIdentityId, $userUpdateUrl, [
            'first_name' => 'foo',
            'last_name' => 'bar',
            'mobile_number' => static::generateRandomMobile(),
            'birthday' => $birthday->format(\DateTime::ATOM),
        ]);

        // retry with a new mobile number if failing
        if ($this->getClientResponseStatusCode() != 200) {
            static::putRequest(self::$client, $cognitoIdentityId, $userUpdateUrl, [
                'first_name' => 'foo',
                'last_name' => 'bar',
                'mobile_number' => static::generateRandomMobile(),
                'birthday' => $birthday->format(\DateTime::ATOM),
            ]);
        }
        $this->verifyResponse(200);

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

    protected function payPolicy($user, $policyId, $amount = null, $date = null)
    {
        // Reload user to get address
        $dm = $this->getDocumentManager(true);
        $userRepo = $dm->getRepository(User::class);
        /** @var User $user */
        $user = $userRepo->find($user->getId());

        $policyRepo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $policyRepo->find($policyId);

        if ($amount) {
            /** @var JudopayService $judopay */
            $judopay = $this->getContainer(true)->get('app.judopay');
            $payDetails = $judopay->testPayDetails(
                $user,
                $policyId,
                $amount,
                self::$JUDO_TEST_CARD_NUM,
                self::$JUDO_TEST_CARD_EXP,
                self::$JUDO_TEST_CARD_PIN
            );

            $cognitoIdentityId = $this->getAuthUser($user);
            $url = sprintf("/api/v1/auth/policy/%s/pay", $policyId);
            $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['judo' => [
                'consumer_token' => $payDetails['consumer']['consumerToken'],
                'card_token' => $payDetails['cardDetails']['cardToken'],
                'receipt_id' => $payDetails['receiptId'],
            ]]);
            $this->verifyResponse(200);
        } else {
            $payment = $this->payPolicyMonthly($user, $policy, $date);
            $dm->persist($payment);
            $dm->flush();
            $this->assertNotNull($payment->getId());
        }
        
        $this->assertNotNull($policy->getUser());
        $this->assertNotNull($policy->getUser()->getBillingAddress());
        if ($policy->getUser()->getBillingAddress()) {
            $this->assertNotNull($policy->getUser()->getBillingAddress()->getLine1());
        }
        /*
        $url = sprintf("/api/v1/auth/policy/%s/pay", $policyId);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, ['bank_account' => [
            'sort_code' => '200000',
            'account_number' => '55779911',
            'first_name' => 'foo',
            'last_name' => 'bar',
        ]]);
        $policyData = $this->verifyResponse(200);
        $this->assertEquals(SalvaPhonePolicy::STATUS_PENDING, $policyData['status']);
        $this->assertEquals($policyId, $policyData['id']);
        */
    }

    protected function payPolicyMonthly($user, $policy, $date = null)
    {
        $payment = new JudoPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
        $policy->addPayment($payment);
        $user->addPolicy($policy);

        static::$policyService->create($policy, $date, true);

        return $payment;
    }

    public static function populateYearlyPostcodes()
    {
        foreach (SoSure::$yearlyOnlyPostcodes as $postcode) {
            $postcode = new Postcode();
            $postcode->setPostcode(PostcodeTrait::normalizePostcode($postcode));
            self::$censusDm->persist($postcode);
        }
        self::$censusDm->flush();
    }
}
