<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Controller\OpsController;

/**
 * @group unit
 */
class CognitoIdentityServiceUnitTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $cognito;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$cognito = self::$container->get('app.cognito.identity');
    }

    public function tearDown()
    {
    }

    public function testParseIdentity()
    {
        // @codingStandardsIgnoreStart
        $identity = "{cognitoIdentityPoolId=eu-west-1:e80351d5-1068-462e-9702-3c9f642507f5, accountId=812402538357, cognitoIdentityId=eu-west-1:85376078-5f1f-43b8-8529-9021bb2096a4, caller=AROAIOCRWVZM5HTY5DI3E:CognitoIdentityCredentials, apiKey=null, sourceIp=62.253.24.189, cognitoAuthenticationType=unauthenticated, cognitoAuthenticationProvider=null, userArn=arn:aws:sts::812402538357:assumed-role/Cognito_sosureUnauth_Role/CognitoIdentityCredentials, userAgent=aws-sdk-iOS/2.3.5 iPhone-OS/9.2.1 en_GB, user=AROAIOCRWVZM5HTY5DI3E:CognitoIdentityCredentials}";
        // @codingStandardsIgnoreEnd
        $body = json_encode(["body" => [], "identity" => $identity]);
        $parsed = self::$cognito->parseIdentity($body);

        $this->assertEquals("eu-west-1:e80351d5-1068-462e-9702-3c9f642507f5", $parsed['cognitoIdentityPoolId']);
        $this->assertEquals("eu-west-1:85376078-5f1f-43b8-8529-9021bb2096a4", $parsed['cognitoIdentityId']);
    }
}
