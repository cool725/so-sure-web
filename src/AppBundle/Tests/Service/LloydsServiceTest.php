<?php

namespace AppBundle\Tests\Service;

use AppBundle\Service\LloydsService;
use Doctrine\ODM\MongoDB\DocumentManager;
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
    /** @var DocumentManager */
    protected static $dm;
    /** @var LloydsService $lloyds */
    protected static $lloyds;
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
         /** @var LloydsService $lloyds */
         $lloyds = self::$container->get('app.lloyds');
         self::$lloyds = $lloyds;

         /** @var DocumentManager */
         $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$dm = $dm;
         self::$rootDir = self::$container->getParameter('kernel.root_dir');
    }

    public function tearDown()
    {
    }

    public function testLloydsProcessCsv()
    {
        $csv = sprintf("%s/../src/AppBundle/Tests/Resources/lloyds.csv", self::$rootDir);

        $data = self::$lloyds->processActualCsv($csv);
        $this->assertEquals(2, count($data['data']), json_encode($data));
        $this->assertEquals(94.41, $data['total']);
    }
}
