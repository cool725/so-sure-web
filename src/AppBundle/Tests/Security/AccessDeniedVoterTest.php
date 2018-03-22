<?php

namespace AppBundle\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * @group functional-nonet
 * AppBundle\\Tests\\Security\\AccessDeniedVoterTest
 */
class AccessDeniedVoterTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $accessDeniedVoter;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$accessDeniedVoter = self::$container->get('rollbar.voter.accessdenied');
    }

    public function tearDown()
    {
    }

    public function testVoteIgnoredException()
    {
        $exception = new \Exception();
        
        // ignored exeptions should be true
        $this->assertTrue(self::$accessDeniedVoter->vote($exception));
    }

    public function testVoteAccessDeniedHttpException()
    {
        $exception = new AccessDeniedHttpException();

        // manually add 0.0.0.0 to config.yml to verify alternative case
        $this->assertFalse(self::$accessDeniedVoter->vote($exception));
    }
}
