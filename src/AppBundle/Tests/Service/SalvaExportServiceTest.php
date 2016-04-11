<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Service\SalvaExportService;

/**
 * @group functional-net
 */
class SalvaExportServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    protected static $container;
    protected static $salva;
    protected static $xmlFile;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$salva = self::$container->get('app.salva');
        self::$xmlFile = sprintf(
            "%s/../src/AppBundle/Tests/Resources/salva-example-boat.xml",
            self::$container->getParameter('kernel.root_dir')
        );
    }

    public function tearDown()
    {
    }

    public function testValidate()
    {
        $xml = file_get_contents(self::$xmlFile);
        $this->assertTrue(self::$salva->validate($xml, SalvaExportService::SCHEMA_POLICY_IMPORT));
    }

    public function testSend()
    {
        $xml = file_get_contents(self::$xmlFile);
        $this->assertTrue(self::$salva->send($xml));
    }
}
