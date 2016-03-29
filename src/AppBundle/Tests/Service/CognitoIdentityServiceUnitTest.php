<?php

namespace AppBundle\Tests\Service;

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
}
