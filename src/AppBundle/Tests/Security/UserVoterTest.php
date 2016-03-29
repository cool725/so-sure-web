<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;

/**
 * @group functional-nonet
 */
class UserVoterTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $userVoter;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$userVoter = self::$container->get('app.voter.user');
    }

    public function tearDown()
    {
    }

    public function testSupportsUnknown()
    {
        $user = new User();
        $this->assertFalse(self::$userVoter->supports('unknown', $user));
        $this->assertFalse(self::$userVoter->supports('view', null));
    }

    public function testSupports()
    {
        $user = new User();
        $this->assertTrue(self::$userVoter->supports('view', $user));
        $this->assertTrue(self::$userVoter->supports('edit', $user));
    }
}
