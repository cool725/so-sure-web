<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Controller\OpsController;

/**
 * @group functional-net
 *
 * AppBundle\\Tests\\Security\\FOSUBUserProviderTest
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
    protected static $dm;

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
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
    }

    public function tearDown()
    {
    }

    public function testLoadUserByOAuthUserResponsePhone()
    {
        $mock = $this->createUserResponseMock('+447775700000', 'facebook');

        $this->assertNull(self::$userService->loadUserByOAuthUserResponse($mock));
    }

    /**
     * @expectedException \Exception
     */
    public function testLoadUserByOAuthUserResponseFacebookEmpty()
    {
        $mock = $this->createUserResponseMock('', 'facebook');

        $this->assertNull(self::$userService->loadUserByOAuthUserResponse($mock));
    }

    /**
     * @expectedException \Exception
     */
    public function testLoadUserByOAuthUserResponseStarlingEmpty()
    {
        $mock = $this->createUserResponseMock('', 'starling');

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

    public function testLoadUserByOAuthUserResponseStarlingFull()
    {
        $email = static::generateEmail('starling-full', $this);
        $mock = $this->createUserResponseMock($email, 'starling');
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
        $mock
            ->expects($this->any())
            ->method('getResponse')
            ->will($this->returnValue(['phone' => '07775740400', 'dateOfBirth' => '1980-06-01']));

        $user = self::$userService->loadUserByOAuthUserResponse($mock);
        $this->assertEquals($email, $user->getEmail());
        $this->assertEquals('A', $user->getFirstName());
        $this->assertEquals('Foo', $user->getLastName());
        $this->assertEquals('+447775740400', $user->getMobileNumber());
        $this->assertEquals(new \DateTime('1980-06-01 00:00:00'), $user->getBirthday());
    }

    public function testLoadUserByOAuthUserResponseStarlingSimple()
    {
        $email = static::generateEmail('starlign-simple', $this);
        $mock = $this->createUserResponseMock($email, 'starling');
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
        $mock
            ->expects($this->any())
            ->method('getResponse')
            ->will($this->returnValue([]));

        $user = self::$userService->loadUserByOAuthUserResponse($mock);
        $this->assertEquals($email, $user->getEmail());
        $this->assertEquals('A', $user->getFirstName());
        $this->assertEquals('Foo', $user->getLastName());
        $this->assertEquals('', $user->getMobileNumber());
        $this->assertEquals(null, $user->getBirthday());
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

    /**
     * @expectedException \HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException
     */
    public function testLoadUserByOAuthUserResponseStarlingExistingUser()
    {
        $email = static::generateEmail('testLoadUserByOAuthUserResponseStarlingExistingUser', $this);
        $user = static::createUser(
            static::$userManager,
            $email,
            'bar'
        );

        $mock = $this->createUserResponseMock($email, 'starling');
        $mock
            ->expects($this->any())
            ->method('getEmail')
            ->will($this->returnValue($email));

        $existingUser = self::$userService->loadUserByOAuthUserResponse($mock);
    }
    
    public function testHasPreviouslyUsedPassword()
    {
        $email = static::generateEmail('testHasPreviouslyUsedPassword', $this);
        $user = static::createUser(
            static::$userManager,
            $email,
            'bar'
        );
        static::$dm->flush();
        $passwords = [];
        for ($i = 0; $i <= 3; $i++) {
            $t = $user->getPassword();
            $password = rand(11111111, 99999999) . 'aA!';
            $user->setPlainPassword($password);
            $passwords[$i] = $password;

            static::$userManager->updatePassword($user);
            static::$dm->flush();
            $this->assertNotEquals($t, $user->getPassword());
            sleep(1);
        }
        
        // 4 passwords in history, 1st from create user + 3 from above
        // current password not in history
        $user->setPlainPassword($passwords[0]);
        $this->assertTrue(static::$userService->hasPreviouslyUsedPassword($user, 4));
        //print PHP_EOL;
        $this->assertFalse(static::$userService->hasPreviouslyUsedPassword($user, 3));

        // current password not allowed either
        $user->setPlainPassword($passwords[3]);
        $this->assertTrue(static::$userService->hasPreviouslyUsedPassword($user, 0));

        $t = $user->getPassword();
        $password = rand(11111111, 99999999) . 'aA!';
        $user->setPlainPassword($password);

        static::$userManager->updatePassword($user);
        static::$dm->flush();
        $this->assertNotEquals($t, $user->getPassword());
        sleep(1);
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
