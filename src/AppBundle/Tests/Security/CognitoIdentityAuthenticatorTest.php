<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Security\CognitoIdentityAuthenticator;

/**
 * @group functional-net
 */
class CognitoIdentityAuthenticatorTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $auth;
    protected static $userProvider;
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
         self::$auth = self::$container->get('app.user.cognitoidentity.authenticator');
         self::$userManager = self::$container->get('fos_user.user_manager');
         self::$userProvider = self::$container->get('app.user.cognitoidentity');
         self::$cognito = self::$container->get('app.cognito.identity');
    }

    public function tearDown()
    {
    }

    public function testUnauthToken()
    {
        $cognitoIdentityId = self::$cognito->getId();
        $request = $this->getRequest($cognitoIdentityId);
        $token = self::$auth->createToken($request, 'login.so-sure.com');
        $this->assertEquals(CognitoIdentityAuthenticator::ANON_USER_UNAUTH_PATH, $token->getUser());
    }

    public function testAuthPathToken()
    {
        $cognitoIdentityId = self::$cognito->getId();
        $request = $this->getAuthRequest($cognitoIdentityId);
        $token = self::$auth->createToken($request, 'login.so-sure.com');
        $this->assertEquals(CognitoIdentityAuthenticator::ANON_USER_AUTH_PATH, $token->getUser());
    }

    public function testPartialPathToken()
    {
        $cognitoIdentityId = self::$cognito->getId();
        $request = $this->getPartialRequest($cognitoIdentityId);
        $token = self::$auth->createToken($request, 'login.so-sure.com');
        $this->assertEquals(CognitoIdentityAuthenticator::ANON_USER_PARTIAL_AUTH_PATH, $token->getUser());
    }

    /**
     * @expectedException Symfony\Component\Security\Core\Exception\BadCredentialsException
     */
    public function testAuthPathTokenException()
    {
        $request = $this->getAuthRequest(null);
        $token = self::$auth->createToken($request, 'login.so-sure.com');
    }

    public function testAuthenticate()
    {
        $user = static::createUser(self::$userManager, 'auth@security.so-sure.com', 'foo');
        $cognitoIdentityId = static::authUser(self::$cognito, $user);

        $request = $this->getAuthRequest($cognitoIdentityId);
        $token = self::$auth->createToken($request, 'login.so-sure.com');
        $authToken = self::$auth->authenticateToken($token, self::$userProvider, 'login.so-sure.com');
        $this->assertEquals('auth@security.so-sure.com', $authToken->getUser()->getEmail());
    }

    public function testPartialAuthenticate()
    {
        $user = static::createUser(self::$userManager, 'partial@security.so-sure.com', 'foo');
        $cognitoIdentityId = static::authUser(self::$cognito, $user);
        $request = $this->getPartialRequest($cognitoIdentityId);
        $token = self::$auth->createToken($request, 'login.so-sure.com');
        $authToken = self::$auth->authenticateToken($token, self::$userProvider, 'login.so-sure.com');
        $this->assertEquals('partial@security.so-sure.com', $authToken->getUser()->getEmail());
    }

    public function testPartialUnAuth()
    {
        $cognitoIdentityId = self::$cognito->getId();
        $request = $this->getPartialRequest($cognitoIdentityId);
        $token = self::$auth->createToken($request, 'login.so-sure.com');
        $authToken = self::$auth->authenticateToken($token, self::$userProvider, 'login.so-sure.com');
        $this->assertEquals(CognitoIdentityAuthenticator::ANON_USER_PARTIAL_AUTH_PATH, $authToken->getUser());
    }

    /**
     * @expectedException Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException
     */
    public function testAuthenticateException()
    {
        $cognitoIdentityId = self::$cognito->getId();
        $request = $this->getAuthRequest($cognitoIdentityId);
        $token = self::$auth->createToken($request, 'login.so-sure.com');
        $authToken = self::$auth->authenticateToken($token, self::$userProvider, 'login.so-sure.com');
    }

    /**
     * @expectedException Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException
     */
    public function testAuthenticateExceptionUserDisabled()
    {
        $user = static::createUser(self::$userManager, self::generateEmail('user-disabled', $this), 'foo');
        $user->setEnabled(false);
        $cognitoIdentityId = static::authUser(self::$cognito, $user);

        $request = $this->getAuthRequest($cognitoIdentityId);
        $token = self::$auth->createToken($request, 'login.so-sure.com');
        $authToken = self::$auth->authenticateToken($token, self::$userProvider, 'login.so-sure.com');
    }

    /**
     * @expectedException Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException
     */
    public function testAuthenticateExceptionUserLocked()
    {
        $user = static::createUser(self::$userManager, self::generateEmail('user-locked', $this), 'foo');
        $user->setLocked(true);
        $cognitoIdentityId = static::authUser(self::$cognito, $user);

        $request = $this->getAuthRequest($cognitoIdentityId);
        $token = self::$auth->createToken($request, 'login.so-sure.com');
        $authToken = self::$auth->authenticateToken($token, self::$userProvider, 'login.so-sure.com');
    }

    public function testGetCognitoIdentityId()
    {
        $identity = "eu-west-1:85376078-5f1f-43b8-8529-9021bb2096a4";
        $body = json_encode(["body" => [], "cognitoIdentityId" => $identity, "sourceIp" => "62.253.24.189"]);
        $cognitoIdentityId = self::$auth->getCognitoIdentityId($body);

        $this->assertEquals("eu-west-1:85376078-5f1f-43b8-8529-9021bb2096a4", $cognitoIdentityId);
    }

    public function testGetCognitoIdentityIp()
    {
        $identity = "eu-west-1:85376078-5f1f-43b8-8529-9021bb2096a4";
        $body = json_encode(["body" => [], "cognitoIdentityId" => $identity, "sourceIp" => "62.253.24.189"]);
        $ip = self::$auth->getCognitoIdentityIp($body);

        $this->assertEquals("62.253.24.189", $ip);
    }

    private function getAuthRequest($cognitoIdentityId)
    {
        return $this->getRequest($cognitoIdentityId, '/api/v1/auth/ping?_method=GET', 'POST');
    }

    private function getPartialRequest($cognitoIdentityId)
    {
        return $this->getRequest($cognitoIdentityId, '/api/v1/partial/ping?_method=GET', 'POST');
    }

    private function getRequest($cognitoIdentityId, $path = "/", $method = "GET")
    {
        $request = new Request();
        $body = json_encode(["body" => [], "cognitoIdentityId" => $cognitoIdentityId, "sourceIp" => "62.253.24.189"]);
        $request = Request::create(
            $path,
            $method,
            [], // $parameters
            [], // $cookies
            [], // $files
            [], // $server
            $body // $content
        );
        $this->assertEquals($body, $request->getContent());

        return $request;
    }
}
