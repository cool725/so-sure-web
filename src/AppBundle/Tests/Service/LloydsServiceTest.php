<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\File\BarclaysFile;
use AppBundle\Document\Payment\JudoPayment;
use Symfony\Component\HttpFoundation\File\File;

/**
 * @group functional-net
 */
class LloydsServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $lloyds;
    protected static $dm;
    protected static $rootDir;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$lloyds = self::$container->get('app.lloyds');
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$rootDir = self::$container->getParameter('kernel.root_dir');
    }

    public function tearDown()
    {
    }

    public function testProcessCsv()
    {
        $csv = sprintf("%s/../src/AppBundle/Tests/Resources/lloyds.csv", self::$rootDir);

        $data = self::$lloyds->processActualCsv($csv);
        $this->assertEquals(3, count($data['data']));
        $this->assertEquals(519.94, $data['total']);
    }
}
