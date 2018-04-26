<?php

namespace AppBundle\Tests\Service;

use AppBundle\Repository\PhoneRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use GeoJson\Geometry\Point;

/**
 * @group functional-paid
 */
class ReceperioServicePaidTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $imei;
    protected static $phoneRepo;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$imei = self::$container->get('app.imei');
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /** @var PhoneRepository phoneRepo */
        self::$phoneRepo = self::$dm->getRepository(Phone::class);
    }

    public function tearDown()
    {
    }

    public function testPaidCheckImei()
    {
        self::$imei->setEnvironment('prod');
        // Found on interenet, valid imei, but lost/stolen
        $this->assertFalse(self::$imei->checkImei(new Phone(), 356938035643809));
        $this->assertTrue(mb_strlen(self::$imei->getCertId()) > 0);

        // Patrick's imei
        $this->assertTrue(self::$imei->checkImei(new Phone(), 355424073417084));
        $this->assertTrue(mb_strlen(self::$imei->getCertId()) > 0);
        self::$imei->setEnvironment('test');
    }

    public function testPaidCheckSerial()
    {
        $iphone6s = static::$phoneRepo->findOneBy(['devices' => 'iPhone 6s', 'memory' => 64]);
        $galaxy = static::$phoneRepo->findOneBy(['devices' => 'ja3g']);
        self::$imei->setEnvironment('prod');

        // Patrick's serial
        $this->assertTrue(self::$imei->checkSerial($iphone6s, "C77QMB7SGRY9", null));
        $responseData = self::$imei->getResponseData();
        $this->assertTrue(self::$imei->validateSamePhone($iphone6s, "C77QMB7SGRY9", $responseData));

        // GALAXY S4 GT-I9500
        $this->assertTrue(self::$imei->checkSerial($galaxy, "35516705720382", null));
        $responseData = self::$imei->getResponseData();
        $this->assertTrue(self::$imei->validateSamePhone($galaxy, "35516705720382", $responseData));

        self::$imei->setEnvironment('test');
    }
}
