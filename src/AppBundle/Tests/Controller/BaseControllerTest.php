<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BaseControllerTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $client;
    protected static $userManager;
    protected static $dm;
    protected static $identity;
    protected static $jwt;
    protected static $router;
    protected static $redis;

    public function tearDown()
    {
    }

    public static function setUpBeforeClass()
    {
        self::$client = self::createClient();
        self::$identity = self::$client->getContainer()->get('app.cognito.identity');
        self::$dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        self::$userManager = self::$client->getContainer()->get('fos_user.user_manager');
        self::$router = self::$client->getContainer()->get('router');
        self::$jwt = self::$client->getContainer()->get('app.jwt');
        self::$redis = self::$container->get('snc_redis.default');
    }

    // helpers

    /**
     *
     */
    protected function getUnauthIdentity()
    {
        return static::getIdentityString(self::$identity->getId());
    }

    protected function getAuthUser($user)
    {
        return static::authUser(self::$identity, $user);
    }

    protected function verifyResponse($statusCode, $errorCode = null)
    {
        $data = json_decode(self::$client->getResponse()->getContent(), true);
        $this->assertEquals($statusCode, self::$client->getResponse()->getStatusCode(), print_r($data, true));
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
