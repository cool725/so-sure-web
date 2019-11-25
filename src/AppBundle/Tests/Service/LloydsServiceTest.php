<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\Payment\BacsIndemnityPayment;
use AppBundle\Service\LloydsService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\File\BarclaysFile;
use AppBundle\Document\Payment\JudoPayment;
use Symfony\Component\HttpFoundation\File\File;

/**
 * @group functional-net
 * @group fixed
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

        $bacsIndemnityA = new BacsIndemnityPayment();
        $bacsIndemnityA->setReference('DDIC00000001301718');
        $bacsIndemnityA->setAmount(10);
        $bacsIndemnityAuto = new BacsIndemnityPayment();
        $bacsIndemnityAuto->setReference('DDICNWBKLE00676955');
        $bacsIndemnityAuto->setAmount(10);
        static::$dm->persist($bacsIndemnityA);
        static::$dm->persist($bacsIndemnityAuto);
        static::$dm->flush();

        $data = self::$lloyds->processActualCsv($csv);
        $this->assertEquals(4, count($data['data']), json_encode($data));
        $this->assertEquals(75.92, $data['total']);

        /** @var DocumentManager $dm */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        $repo = $dm->getRepository(BacsIndemnityPayment::class);
        /** @var BacsIndemnityPayment $updatedBacsIndemnityA */
        $updatedBacsIndemnityA = $repo->find($bacsIndemnityA->getId());
        /** @var BacsIndemnityPayment $updatedBacsIndemnityAuto */
        $updatedBacsIndemnityAuto = $repo->find($bacsIndemnityAuto->getId());

        $this->assertTrue($updatedBacsIndemnityA->hasSuccess());
        $this->assertEquals(BacsIndemnityPayment::STATUS_REFUNDED, $updatedBacsIndemnityA->getStatus());
        $this->assertEquals(new \DateTime('2017-04-28'), $updatedBacsIndemnityA->getDate());

        $this->assertTrue($updatedBacsIndemnityAuto->hasSuccess());
        $this->assertEquals($updatedBacsIndemnityAuto::STATUS_REFUNDED, $updatedBacsIndemnityAuto->getStatus());
        $this->assertEquals(new \DateTime('2017-04-30'), $updatedBacsIndemnityAuto->getDate());
    }
}
