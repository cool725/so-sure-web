<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group functional-nonet
 * @group fixed
 */
class JWTServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $jwt;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$jwt = self::$container->get('app.jwt');
    }

    public function tearDown()
    {
    }

    public function testJWTOk()
    {
        $cognitoId = '1234';
        $token = self::$jwt->create($cognitoId, ['foo' => 'bar']);
        $this->assertTrue(mb_strlen($token) > 50);

        $data = self::$jwt->validate($cognitoId, $token);
        $this->assertEquals('bar', $data['foo']);

        $data = self::$jwt->validate($cognitoId, $token, ['foo' => 'bar']);
        $this->assertEquals('bar', $data['foo']);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testJWTAdditionalValidations()
    {
        $cognitoId = '1234';
        $token = self::$jwt->create($cognitoId, ['foo' => 'bar']);
        $data = self::$jwt->validate($cognitoId, $token, ['foo' => 'foo']);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testJWTSecretIsTransformed()
    {
        $cognitoId = '1234';
        $token = self::$jwt->create($cognitoId, ['foo' => 'bar']);
        self::$jwt->setSecret(self::$container->getParameter('api_secret'), false);
        $data = self::$jwt->validate($cognitoId, $token);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testJWTDiffCognito()
    {
        $cognitoId = '1234';
        $token = self::$jwt->create($cognitoId, ['foo' => 'bar']);
        $this->assertTrue(mb_strlen($token) > 50);

        $data = self::$jwt->validate('1', $token);
    }
}
