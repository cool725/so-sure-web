<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\SCode;
use AppBundle\Document\Sns;
use AppBundle\Document\Policy;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use AppBundle\Service\SixpackService;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Controller\\ApiPartialControllerTest
 */
class ApiPartialControllerTest extends BaseApiControllerTest
{
    protected static $endpoint1;
    protected static $endpoint2;

    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();

        // @codingStandardsIgnoreStart
        self::$endpoint1 = 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/5e1cbe93-5f08-3e00-ad40-acb1fd3763af';
        self::$endpoint2 = 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/5b1217b3-1865-35b2-8e52-27a58cf8441a';
        // @codingStandardsIgnoreEnd
    }

    // ab

    /**
     *
     */
    public function testABUnknownTest()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/partial/ab/%s?_method=GET', 'unknown');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, array());
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testABWithRequiredUserNoUser()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/partial/ab/%s?_method=GET', SixpackService::EXPIRED_EXPERIMENT_SHARE_MESSAGE);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, array());
        $data = $this->verifyResponse(404, ApiErrorCode::ERROR_NOT_FOUND);
    }

    public function testAB()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testAB', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $phone = self::getRandomPhone(self::$dm);
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();

        $url = sprintf('/api/v1/partial/ab/%s?_method=GET', SixpackService::EXPIRED_EXPERIMENT_SHARE_MESSAGE);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, array());
        $data = $this->verifyResponse(200);
    }

    public function testABNoScode()
    {
        $user = self::createUser(
            self::$userManager,
            self::generateEmail('testABNoScode', $this),
            'foo'
        );
        $cognitoIdentityId = $this->getAuthUser($user);
        $phone = self::getRandomPhone(self::$dm);
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        foreach ($policy->getScodes() as $scode) {
            $scode->setActive(false);
        }
        self::$dm->flush();

        $url = sprintf('/api/v1/partial/ab/%s?_method=GET', SixpackService::EXPIRED_EXPERIMENT_SHARE_MESSAGE);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, array());
        $data = $this->verifyResponse(404);

        $url = sprintf('/api/v1/partial/ab/%s?_method=GET', SixpackService::EXPERIMENT_APP_SHARE_METHOD);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, array());
        $data = $this->verifyResponse(404);
    }

    // feature flags

    /**
     *
     */
    public function testFeatureFlags()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $url = sprintf('/api/v1/partial/feature-flags?_method=GET');
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, array());
        $data = $this->verifyResponse(200);
        $this->assertTrue(isset($data['flags']));
        $this->assertTrue(count($data['flags']) > 0);
    }

    // sns

    /**
     *
     */
    public function testSns()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/partial/sns', array(
            'endpoint' => self::$endpoint1,
            'platform' => 'Android',
            'version' => '0.0.0',
        ));
        $data = $this->verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Sns::class);
        $sns = $repo->findOneBy(['endpoint' => self::$endpoint1]);
        $this->assertNotNull($sns, 'If failure, time on system may need to be updated');
        $this->assertTrue(mb_strlen($sns->getAll()) > 0);
        $this->assertTrue(mb_strlen($sns->getUnregistered()) > 0);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/partial/sns', array(
            'endpoint' => self::$endpoint2,
            'old_endpoint' => self::$endpoint1,
        ));
        $data = $this->verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Sns::class);
        $sns1 = $repo->findOneBy(['endpoint' => self::$endpoint1]);
        $sns2 = $repo->findOneBy(['endpoint' => self::$endpoint2]);
        $this->assertNull($sns1);
        $this->assertNotNull($sns2);
        $this->assertTrue(mb_strlen($sns2->getAll()) > 0);
        $this->assertTrue(mb_strlen($sns2->getUnregistered()) > 0);
    }

    public function testSnsMissingEndpoint()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/partial/sns', array());
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testSnsWithUser()
    {
        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-a', $this),
            'foo'
        );
        $userA->setSnsEndpoint(self::$endpoint1);

        $userB = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-b', $this),
            'foo'
        );
        $userB->setSnsEndpoint(self::$endpoint1);

        $userC = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-c', $this),
            'foo'
        );

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $dm->flush();
        
        $cognitoIdentityId = $this->getAuthUser($userC);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/partial/sns', array(
            'endpoint' => self::$endpoint1,
        ));
        $data = $this->verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Sns::class);
        $sns = $repo->findOneBy(['endpoint' => self::$endpoint1]);
        $this->assertNotNull($sns);

        $userRepo = $dm->getRepository(User::class);
        $changedUserA = $userRepo->findOneBy(['email' => $userA->getEmail()]);
        $changedUserB = $userRepo->findOneBy(['email' => $userB->getEmail()]);
        $changedUserC = $userRepo->findOneBy(['email' => $userC->getEmail()]);
        $this->assertEquals(self::$endpoint1, $changedUserC->getSnsEndpoint());
        $this->assertNull($changedUserA->getSnsEndpoint());
        $this->assertNull($changedUserB->getSnsEndpoint());
    }
}
