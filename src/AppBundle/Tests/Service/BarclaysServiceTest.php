<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\File\BarclaysFile;
use AppBundle\Document\Payment\JudoPayment;
use Symfony\Component\HttpFoundation\File\File;

/**
 * @group functional-net
 * AppBundle\\Tests\\Service\\BarclaysServiceTest
 */
class BarclaysServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $barclays;
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
         self::$barclays = self::$container->get('app.barclays');
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$rootDir = self::$container->getParameter('kernel.root_dir');
    }

    public function tearDown()
    {
    }

    public function testProcessCsv()
    {
        $payment = new JudoPayment();
        $payment->setAmount(8.64);
        $payment->setDate(new \DateTime('2016-06-10 15:00'));
        $payment->setCardLastFour("9876");
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        static::$dm->persist($payment);
        static::$dm->flush();

        $csv = sprintf("%s/../src/AppBundle/Tests/Resources/barclays.csv", self::$rootDir);
        $barclaysFile = new BarclaysFile();
        $barclaysFile->setFile(new File($csv));

        $data = self::$barclays->processCsv($barclaysFile);
        $this->assertEquals(8.60, $data['total']);

        $repo = static::$dm->getRepository(JudoPayment::class);
        $updatedPayment = $repo->find($payment->getId());
        $this->assertEquals("74579156163006476980327", $updatedPayment->getBarclaysReference());
    }
}
