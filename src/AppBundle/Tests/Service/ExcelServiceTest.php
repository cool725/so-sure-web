<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 */
class ExcelTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $excel;
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
         self::$excel = self::$container->get('app.excel');
         self::$rootDir = self::$container->getParameter('kernel.root_dir');
    }

    public function tearDown()
    {
    }
    
    public function testConvert()
    {
        $davies = sprintf("%s/../src/AppBundle/Tests/Resources/davies-extract.xlsx", self::$rootDir);
        $csv = sprintf("%s/davies.csv", sys_get_temp_dir());
        self::$excel->convertToCsv($davies, $csv);
        $this->assertTrue(file_exists($csv));

        $data = array_map('str_getcsv', file($csv));
        $this->assertEquals('So-Sure', $data[1][0]);
        $this->assertEquals(250.49, $data[1][15]);
    }
}
