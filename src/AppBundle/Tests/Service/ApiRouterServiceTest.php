<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * @group functional-nonet
 */
class ApiRouterServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $apiRouter;

    /** @var RouterInterface */
    protected static $router;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$apiRouter = self::$container->get('api.router');
         self::$router = self::$container->get('router');
    }

    public function tearDown()
    {
    }

    public function testNoPort()
    {
        self::$router->getContext()->setHttpPort(8080);
        self::$router->getContext()->setHttpsPort(8080);
        $url = self::$router->generate('homepage', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->assertContains(':8080', $url);
    }

    public function testPort()
    {
        self::$apiRouter->getRouter()->getContext()->setHttpPort(8080);
        self::$apiRouter->getRouter()->getContext()->setHttpsPort(8080);
        $url = self::$apiRouter->getRouter()->generate('homepage', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->assertNotContains(':8080', $url);
    }
}
