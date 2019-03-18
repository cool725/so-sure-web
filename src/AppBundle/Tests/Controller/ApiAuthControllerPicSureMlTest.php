<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Classes\SoSure;
use AppBundle\Document\PhonePolicy;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Charge;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\LostPhone;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\SCode;
use AppBundle\Document\MultiPay;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Event\UserEmailEvent;
use AppBundle\Listener\UserListener;
use AppBundle\Service\RateLimitService;
use AppBundle\Document\Invitation\EmailInvitation;

/**
 * @group functional-picsureml
 */
class ApiAuthControllerPicSureMlTest extends BaseApiControllerTest
{
    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
    }

    public function setUp()
    {
    }

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

        // allow time for event listener to trigger and update db
        sleep(1);

        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyByIdExists(self::$client->getContainer(), $policyData['id']);

        $this->assertEquals(PhonePolicy::PICSURE_STATUS_MANUAL, $updatedPolicy->getPicSureStatus());
        $files = $updatedPolicy->getPolicyPicSureFiles();
        $metadata = $files[0]->getMetadata();
        $this->assertTrue(isset($metadata['picsure-ml-score']), 'Check picsure ml can be run on server');
    }
}
