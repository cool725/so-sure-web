<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BaseControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $client;
    protected static $container;
    protected static $userManager;
    protected static $dm;
    protected static $identity;
    protected static $jwt;
    protected static $router;
    protected static $redis;
    protected static $policyService;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        self::$client = self::createClient();
        self::$container = self::$client->getContainer();
        self::$identity = self::$container->get('app.cognito.identity');
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$router = self::$container->get('router');
        self::$jwt = self::$container->get('app.jwt');
        self::$redis = self::$container->get('snc_redis.default');
        self::$policyService = self::$container->get('app.policy');
    }

    // helpers

    /**
     *
     */
    protected function getUnauthIdentity()
    {
        return self::$identity->getId();
    }

    protected function getNewDocumentManager()
    {
        $client = self::createClient();
        return $client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
    }

    protected function getAuthUser($user)
    {
        return static::authUser(self::$identity, $user);
    }

    protected function verifyResponseHtml($statusCode = 200)
    {
        $this->assertEquals($statusCode, self::$client->getResponse()->getStatusCode());

        return self::$client->getResponse()->getContent();
    }

    protected function verifyResponse($statusCode, $errorCode = null)
    {
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals($statusCode, self::$client->getResponse()->getStatusCode(), json_encode($data));
        if ($errorCode) {
            $this->assertEquals($errorCode, $data['code']);
        }

        return $data;
    }

    protected function clearRateLimit()
    {
        // clear out redis rate limiting
        self::$client->getContainer()->get('snc_redis.default')->flushdb();
    }

    protected function getValidationData($cognitoIdentityId, $validateData)
    {
        return static::$jwt->create(
            $cognitoIdentityId,
            $validateData
        );
    }
}
