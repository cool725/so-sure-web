<?php

namespace AppBundle\Tests\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

/**
 * @group functional-nonet
 */
class LaunchUserServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $launch;
    protected static $userRepo;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$launch = self::$container->get('app.user.launch');
         /** @var DocumentManager */
         $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$dm = $dm;
         self::$userRepo = self::$dm->getRepository(User::class);
    }

    public function tearDown()
    {
    }

    public function testExistingUser()
    {
        $this->addUser();

        $fooUser = self::$userRepo->findOneBy(['email' => 'foo@bar.com']);
        $this->assertTrue($fooUser !== null);

        $existingUser = $this->addUser();
        $this->assertFalse($existingUser['new']);
    }

    private function addUser()
    {
        $newUser = new User();
        $newUser->setEmail('foo@bar.com');

        return self::$launch->addUser($newUser);
    }
}
