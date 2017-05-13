<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Controller\OpsController;

/**
 * @group functional-net
 */
class FOSUBUserProviderTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $cognito;
    protected static $userProvider;
    protected static $userManager;
    protected static $userService;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$userProvider = self::$container->get('app.user.cognitoidentity');
        self::$cognito = self::$container->get('app.cognito.identity');
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$userService = self::$container->get('app.user');
    }

    public function tearDown()
    {
    }

    public function testLoadUserByOAuthUserResponsePhone()
    {
        $mock = $this->createUserResponseMock('+447775700000', 'facebook');

        $this->assertNull(self::$userService->loadUserByOAuthUserResponse($mock));
    }

    public function testLoadUserByOAuthUserResponseFacebook()
    {
        $email = static::generateEmail('testLoadUserByOAuthUserResponseFacebook', $this);
        $mock = $this->createUserResponseMock($email, 'facebook');
        $mock
            ->expects($this->any())
            ->method('getEmail')
            ->will($this->returnValue($email));
        $mock
            ->expects($this->any())
            ->method('getFirstName')
            ->will($this->returnValue('A.'));
        $mock
            ->expects($this->any())
            ->method('getLastName')
            ->will($this->returnValue('Foo Bar'));

        $user = self::$userService->loadUserByOAuthUserResponse($mock);
        $this->assertEquals($email, $user->getEmail());
        $this->assertEquals('A', $user->getFirstName());
        $this->assertEquals('Foo', $user->getLastName());
    }

    /**
     * @expectedException \HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException
     */
    public function testLoadUserByOAuthUserResponseFacebookExistingUser()
    {
        $email = static::generateEmail('existing-user', $this);
        $user = static::createUser(
            static::$userManager,
            $email,
            'bar'
        );

        $mock = $this->createUserResponseMock($email, 'facebook');
        $mock
            ->expects($this->any())
            ->method('getEmail')
            ->will($this->returnValue($email));

        $existingUser = self::$userService->loadUserByOAuthUserResponse($mock);
    }

    protected function createResourceOwnerMock($resourceOwnerName = null)
    {
        $resourceOwnerMock = $this->getMockBuilder('HWI\Bundle\OAuthBundle\OAuth\ResourceOwnerInterface')
            ->disableOriginalConstructor()
            ->getMock();

        if (null !== $resourceOwnerName) {
            $resourceOwnerMock
                ->expects($this->any())
                ->method('getName')
                ->will($this->returnValue($resourceOwnerName));
        }

        return $resourceOwnerMock;
    }

    protected function createUserResponseMock($username = null, $resourceOwnerName = null)
    {
        $responseMock = $this->getMockBuilder('HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface')
            ->disableOriginalConstructor()
            ->getMock();

        if (null !== $resourceOwnerName) {
            $responseMock
                ->expects($this->any())
                ->method('getResourceOwner')
                ->will($this->returnValue($this->createResourceOwnerMock($resourceOwnerName)));
        }

        if (null !== $username) {
            $responseMock
                ->expects($this->once())
                ->method('getUsername')
                ->will($this->returnValue($username));
        }

        return $responseMock;
    }
}
