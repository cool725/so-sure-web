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
 */
class ApiPartialControllerTest extends BaseControllerTest
{
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
        $url = sprintf('/api/v1/partial/ab/%s?_method=GET', SixpackService::EXPERIMENT_SHARE_MESSAGE);
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

        $url = sprintf('/api/v1/partial/ab/%s?_method=GET', SixpackService::EXPERIMENT_SHARE_MESSAGE);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, $url, array());
        $data = $this->verifyResponse(200);
    }

    // sns

    /**
     *
     */
    public function testSns()
    {
        // @codingStandardsIgnoreStart
        $endpoint1 = 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/344008b8-a266-3d7b-baa4-f1e8cf9fc16e';
        $endpoint2 = 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/f09de5ae-9a07-36b3-950d-db1dfee0102f';
        // @codingStandardsIgnoreEnd

        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/partial/sns', array(
            'endpoint' => $endpoint1,
            'platform' => 'Android',
            'version' => '0.0.0',
        ));
        $data = $this->verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Sns::class);
        $sns = $repo->findOneBy(['endpoint' => $endpoint1]);
        $this->assertNotNull($sns);
        $this->assertTrue(strlen($sns->getAll()) > 0);
        $this->assertTrue(strlen($sns->getUnregistered()) > 0);

        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/partial/sns', array(
            'endpoint' => $endpoint2,
            'old_endpoint' => $endpoint1,
        ));
        $data = $this->verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Sns::class);
        $sns1 = $repo->findOneBy(['endpoint' => $endpoint1]);
        $sns2 = $repo->findOneBy(['endpoint' => $endpoint2]);
        $this->assertNull($sns1);
        $this->assertNotNull($sns2);
        $this->assertTrue(strlen($sns2->getAll()) > 0);
        $this->assertTrue(strlen($sns2->getUnregistered()) > 0);
    }

    public function testSnsMissingEndpoint()
    {
        $cognitoIdentityId = $this->getUnauthIdentity();
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/partial/sns', array());
        $data = $this->verifyResponse(400, ApiErrorCode::ERROR_MISSING_PARAM);
    }

    public function testSnsWithUser()
    {
        // @codingStandardsIgnoreStart
        $endpoint1 = 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/344008b8-a266-3d7b-baa4-f1e8cf9fc16e';
        $endpoint2 = 'arn:aws:sns:eu-west-1:812402538357:endpoint/GCM/so-sure_android/f09de5ae-9a07-36b3-950d-db1dfee0102f';
        // @codingStandardsIgnoreEnd

        $userA = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-a', $this),
            'foo'
        );
        $userA->setSnsEndpoint($endpoint1);

        $userB = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-b', $this),
            'foo'
        );
        $userB->setSnsEndpoint($endpoint1);

        $userC = self::createUser(
            self::$userManager,
            self::generateEmail('user-sns-c', $this),
            'foo'
        );

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $dm->flush();
        
        $cognitoIdentityId = $this->getAuthUser($userC);
        $crawler = static::postRequest(self::$client, $cognitoIdentityId, '/api/v1/partial/sns', array(
            'endpoint' => $endpoint1,
        ));
        $data = $this->verifyResponse(200);

        $dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(Sns::class);
        $sns = $repo->findOneBy(['endpoint' => $endpoint1]);
        $this->assertNotNull($sns);

        $userRepo = $dm->getRepository(User::class);
        $changedUserA = $userRepo->findOneBy(['email' => $userA->getEmail()]);
        $changedUserB = $userRepo->findOneBy(['email' => $userB->getEmail()]);
        $changedUserC = $userRepo->findOneBy(['email' => $userC->getEmail()]);
        $this->assertEquals($endpoint1, $changedUserC->getSnsEndpoint());
        $this->assertNull($changedUserA->getSnsEndpoint());
        $this->assertNull($changedUserB->getSnsEndpoint());
    }
}
