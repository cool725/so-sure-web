<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Classes\DaviesHandlerClaim;

/**
 * @group functional-nonet
 * @group fixed
 */
class ExcelServiceTest extends WebTestCase
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
        $davies = sprintf("%s/../src/AppBundle/Tests/Resources/So-SureDailyClaimsReport.xls", self::$rootDir);
        $csv = sprintf("%s/davies.csv", sys_get_temp_dir());
        self::$excel->convertToCsv($davies, $csv, 'Cumulative');
        $this->assertTrue(file_exists($csv));

        $data = array_map('str_getcsv', file($csv));
        $this->assertEquals('So-Sure -Mobile', $data[3][0]);
        $this->assertEquals('32000000000', $data[3][1]);
        $this->assertEquals('Mr Foo Bar', $data[3][2]);
        $this->assertEquals('BX1 1LT', $data[3][3]);
        $this->assertEquals('42691', $data[3][4]);
        $this->assertEquals('42674', $data[3][5]);
        $this->assertEquals('43038', $data[3][6]);
        $this->assertEquals('Loss - From Pocket', $data[3][7]);
        $this->assertEquals('Description', $data[3][8]);
        $this->assertEquals('Taxi', $data[3][9]);
        $this->assertEquals('Re-Closed', $data[3][10]);
        $this->assertEquals('Settled', $data[3][11]);
        $this->assertEquals('264504', $data[3][12]);
        $this->assertEquals('Samsung', $data[3][13]);
        $this->assertEquals('Galaxy S7 Edge', $data[3][14]);
        $this->assertEquals('127500080375394', $data[3][15]);
        $this->assertEquals('22/11/2016', $data[3][16]);
        $this->assertEquals('579.38', $data[3][17]);
        $this->assertEquals('0', $data[3][18]);
        $this->assertEquals('0', $data[3][19]);
        $this->assertEquals('0', $data[3][20]);
        $this->assertEquals('0', $data[3][21]);
        $this->assertEquals('0', $data[3][22]);
        $this->assertEquals('1.08', $data[3][23]);
        $this->assertEquals('0.3100000000000001', $data[3][24]);
        $this->assertEquals('0', $data[3][25]);
        $this->assertEquals('0', $data[3][26]);
        $this->assertEquals('525.77', $data[3][27]);
        $this->assertEquals('15', $data[3][28]);
        $this->assertEquals('70', $data[3][29]);
        $this->assertEquals('Mob/2016/5400000', $data[3][30]);
        $this->assertEquals('42695', $data[3][31]);
        $this->assertEquals('42695', $data[3][32]);
        $this->assertEquals('42699', $data[3][33]);
        $this->assertEquals('123 Foo St BX1 1LT', $data[3][34]);
        $this->assertEquals('580.7699999999996', $data[3][35]);
    }
}
