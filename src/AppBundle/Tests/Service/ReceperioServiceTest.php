<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use GeoJson\Geometry\Point;

/**
 * @group functional-nonet
 */
class ReceperioServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $imei;
    protected static $dm;
    protected static $phoneRepo;
    protected static $phoneA;
    protected static $phoneB;

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
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phoneA = self::$phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneB = self::$phoneRepo->findOneBy(['devices' => 'A0001', 'memory' => 64]);
    }

    public function tearDown()
    {
    }

    public function testAreSameModelMemory()
    {
        $this->assertTrue(self::$imei->areSameModelMemory([
            ['name' => 'foo', 'storage' => '16GB', 'color' => 'white'],
            ['name' => 'foo', 'storage' => '16GB', 'color' => 'black'],
        ]));

        $this->assertFalse(self::$imei->areSameModelMemory([
            ['name' => 'foo', 'storage' => '16GB', 'color' => 'white'],
            ['name' => 'bar', 'storage' => '16GB', 'color' => 'white'],
        ]));

        $this->assertFalse(self::$imei->areSameModelMemory([
            ['name' => 'foo', 'storage' => '16GB', 'color' => 'white'],
            ['name' => 'foo', 'storage' => '32GB', 'color' => 'white'],
        ]));
    }

    /**
     * @expectedException \Exception
     */
    public function testValidateSamePhoneMissingData()
    {
        self::$imei->validateSamePhone(static::$phoneA, '123', []);
    }

    /**
     * @expectedException \Exception
     */
    public function testValidateSamePhoneMissingModel()
    {
        $data['makes'][] = ['make' => 'Apple'];
        self::$imei->validateSamePhone(static::$phoneA, '123', $data);
    }

    public function testValidateSamePhoneApple()
    {
        $models[] = ['name' => 'iPhone 5', 'storage' => '64GB'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertTrue(self::$imei->validateSamePhone(static::$phoneA, '123', $data));
    }

    public function testValidateSamePhoneAndroid()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB', 'modelreference' => 'A0001'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertTrue(self::$imei->validateSamePhone(static::$phoneB, '123', $data));
    }

    public function testValidateSamePhoneAndroidMissingModelRef()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertTrue(self::$imei->validateSamePhone(static::$phoneB, '123', $data));
    }
}
