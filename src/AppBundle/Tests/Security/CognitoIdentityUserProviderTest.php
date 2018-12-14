<?php

namespace AppBundle\Tests\Security;

use AppBundle\Security\CognitoIdentityUserProvider;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Service\CognitoIdentityService;
use FOS\UserBundle\Model\UserManager;
use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Controller\OpsController;

/**
 * @group functional-net
 */
class CognitoIdentityUserProviderTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;

    /** @var CognitoIdentityService */
    protected static $cognito;

    /** @var CognitoIdentityUserProvider */
    protected static $userProvider;

    /** @var UserManagerInterface */
    protected static $userManager;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead

         /** @var CognitoIdentityUserProvider $userProvider */
         $userProvider = self::$container->get('app.user.cognitoidentity');
         self::$userProvider = $userProvider;

         /** @var CognitoIdentityService $cognito */
         $cognito = self::$container->get('app.cognito.identity');
         self::$cognito = $cognito;

         /** @var UserManagerInterface userManager */
         $userManager = self::$container->get('fos_user.user_manager');
         self::$userManager = $userManager;
    }

    public function tearDown()
    {
    }

    public function testIdentity()
    {
        $user = static::createUser(self::$userManager, 'provider@service.so-sure.com', 'foo');
        list($identityId, $token) = self::$cognito->getCognitoIdToken($user);

        $searchUser = self::$userProvider->loadUserByCognitoIdentityId($identityId);

        $this->assertNotNull($user);
        $this->assertNotNull($searchUser);
        if ($user && $searchUser) {
            $this->assertEquals($searchUser->getId(), $user->getId());
        }
    }
}
