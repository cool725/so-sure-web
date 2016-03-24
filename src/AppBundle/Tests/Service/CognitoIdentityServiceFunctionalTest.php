<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Controller\OpsController;

/**
 * @group functional-net
 */
class CognitoIdentityServiceFunctionalTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $cognito;
    protected static $userManager;
    protected static $userProvider;

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
         self::$userManager = self::$container->get('fos_user.user_manager');
         self::$userProvider = self::$container->get('app.user.cognitoidentity');
    }

    public function tearDown()
    {
    }

    public function testIdentity()
    {
        $user = $this->createUser('identity@service.so-sure.com', 'foo');
        list($identityId, $token) = self::$cognito->getCognitoIdToken($user);
        $searchUser = self::$userProvider->loadUserByCognitoIdentityId($identityId);
        $this->assertEquals($searchUser->getId(), $user->getId());
    }

    // helpers

    /**
     *
     */
    protected function createUser($email, $password)
    {
        $user = self::$userManager->createUser();
        $user->setEmail($email);
        $user->setPlainPassword($password);
        self::$userManager->updateUser($user, true);

        return $user;
    }
}
