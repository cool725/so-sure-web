<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use GeoJson\Geometry\Point;
use AppBundle\Document\Claim;
use AppBundle\Service\ReceperioService;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Service\\ReceperioServiceTest
 */
class ReceperioServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $imei;
    protected static $dm;
    protected static $phoneRepo;
    protected static $phone;
    protected static $phoneA;
    protected static $phoneB;
    protected static $phoneC;
    protected static $phoneD;

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
        self::$phone = self::$phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneA = self::$phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneB = self::$phoneRepo->findOneBy(['devices' => 'A0001', 'memory' => 64]);
        self::$phoneC = self::$phoneRepo->findOneBy(['devices' => 'hero2lte']);
        self::$phoneD = self::$phoneRepo->findOneBy(['devices' => 'OnePlus3']);
    }

    public function tearDown()
    {
    }

    public function testAreSameModelMemory()
    {
        $this->assertTrue(self::$imei->areSameModel([
            ['name' => 'foo', 'storage' => '16GB', 'color' => 'white'],
            ['name' => 'foo', 'storage' => '16GB', 'color' => 'black'],
        ], true));

        $this->assertFalse(self::$imei->areSameModel([
            ['name' => 'foo', 'storage' => '16GB', 'color' => 'white'],
            ['name' => 'bar', 'storage' => '16GB', 'color' => 'white'],
        ], true));

        $this->assertFalse(self::$imei->areSameModel([
            ['name' => 'foo', 'storage' => '16GB', 'color' => 'white'],
            ['name' => 'foo', 'storage' => '32GB', 'color' => 'white'],
        ], true));

        $this->assertTrue(self::$imei->areSameModel([
            ['name' => 'foo', 'storage' => '16GB', 'color' => 'white'],
            ['name' => 'foo', 'storage' => '32GB', 'color' => 'white'],
        ], false));
    }

    /**
     * @expectedException AppBundle\Exception\ReciperoManualProcessException
     */
    public function testValidateSamePhoneMissingData()
    {
        self::$imei->validateSamePhone(static::$phoneA, '123', []);
    }

    /**
     * @expectedException AppBundle\Exception\ReciperoManualProcessException
     */
    public function testValidateSamePhoneNoData()
    {
        $this->assertTrue(self::$imei->validateSamePhone(static::$phoneA, '123', ['makes' => []]));
    }

    /**
     * @expectedException AppBundle\Exception\ReciperoManualProcessException
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

    public function testValidateSamePhoneAndroidCase()
    {
        $models[] = ['name' => 'GALAXY S7 EDGE', 'storage' => '', 'modelreference' => 'HERO2LTE'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertTrue(self::$imei->validateSamePhone(static::$phoneC, '123', $data));
    }

    public function testValidateSamePhoneAndroidMixedCase()
    {
        $models[] = ['name' => '3', 'storage' => '', 'modelreference' => 'ONEPLUS3'];
        $data['makes'][] = ['make' => 'ONEPLUS', 'models' => $models];
        $this->assertTrue(self::$imei->validateSamePhone(static::$phoneD, '123', $data));
    }

    public function testValidateSamePhoneAndroidDifferentModel()
    {
        $models[] = ['name' => 'GALAXY S7 EDGE', 'storage' => '', 'modelreference' => 'HERO2LTE'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertFalse(self::$imei->validateSamePhone(static::$phoneB, '123', $data));
    }

    /**
     * @expectedException AppBundle\Exception\ReciperoManualProcessException
     */
    public function testValidateSamePhoneAndroidMissingModelRef()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        self::$imei->validateSamePhone(static::$phoneB, '123', $data);
    }

    public function testValidateSamePhoneAndroidMultipleMemory()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB', 'modelreference' => 'A0001'];
        $models[] = ['name' => 'A', 'storage' => '16GB', 'modelreference' => 'A0001'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertTrue(self::$imei->validateSamePhone(static::$phoneB, $this->generateRandomImei(), $data));
    }

    /**
     * @expectedException AppBundle\Exception\ReciperoManualProcessException
     */
    public function testValidateSamePhoneAndroidDifferentModelMem()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB', 'modelreference' => 'A0001'];
        $models[] = ['name' => 'B', 'storage' => '64GB', 'modelreference' => 'A0001'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        self::$imei->validateSamePhone(static::$phoneB, '123', $data);
    }

    /**
     * @expectedException AppBundle\Exception\ReciperoManualProcessException
     */
    public function testValidateSamePhoneAppleMultipleMemory()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB'];
        $models[] = ['name' => 'A', 'storage' => '16GB'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertTrue(self::$imei->validateSamePhone(static::$phoneA, '123', $data));
    }

    /**
     * @expectedException AppBundle\Exception\ReciperoManualProcessException
     */
    public function testValidateSamePhoneAppleDifferentModel()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB'];
        $models[] = ['name' => 'A', 'storage' => '16GB'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        self::$imei->validateSamePhone(static::$phoneA, '123', $data);
    }

    public function testValidateSamePhoneAppleDifferentColours()
    {
        $models[] = ['name' => 'iPhone 5', 'storage' => '64GB', 'color' => 'white'];
        $models[] = ['name' => 'iPhone 5', 'storage' => '64GB', 'color' => 'black'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertTrue(self::$imei->validateSamePhone(static::$phoneA, '123', $data));
    }

    public function testValidateSamePhoneAppleIncorrectModel()
    {
        $models[] = ['name' => 'foo', 'storage' => '16GB', 'color' => 'white'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertFalse(self::$imei->validateSamePhone(static::$phoneA, '123', $data));
    }

    public function testValidateSamePhoneAppleIncorrectMemory()
    {
        $models[] = ['name' => 'iPhone 5', 'storage' => '16GB', 'color' => 'white'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertFalse(self::$imei->validateSamePhone(static::$phoneA, '123', $data));
    }

    public function testValidateSamePhoneAppleImeiSkipsMemory()
    {
        $models[] = ['name' => 'iPhone 5', 'storage' => '16GB', 'color' => 'white'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertTrue(self::$imei->validateSamePhone(static::$phoneA, $this->generateRandomImei(), $data));
    }

    /**
     * @expectedException AppBundle\Exception\ReciperoManualProcessException
     */
    public function testValidateSamePhoneAppleMissingStorage()
    {
        $models[] = ['name' => 'iPhone 5', 'color' => 'white'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertFalse(self::$imei->validateSamePhone(static::$phoneA, '123', $data));
    }

    /**
     * @expectedException \Exception
     */
    public function testPolicyClaimThrowException()
    {
        $policy = self::createUserPolicy(true);
        $claim = new Claim();
        self::$imei->policyClaim($policy, Claim::TYPE_DAMAGE, $claim);
        $this->assertTrue(true);

        $now = new \DateTime();
        $yesterday = new \DateTime();
        $yesterday = $yesterday->sub(new \DateInterval('P1D'));
        $claim->setRecordedDate($yesterday);
        $policy->setImeiReplacementDate($now);
        self::$imei->policyClaim($policy, Claim::TYPE_DAMAGE, $claim);
    }

    public function testAppleManulProcessSerialRetry()
    {
        $this->assertFalse(self::$imei->runMakeModelCheck(ReceperioService::TEST_INVALID_SERIAL));
        self::$imei->checkSerial(
            static::$phoneA,
            ReceperioService::TEST_MANUAL_PROCESS_SERIAL,
            $this->generateRandomImei()
        );
        $this->assertEquals('serial', self::$imei->getResponseData());
    }

    public function testAppleInvalidSerialNoRetry()
    {
        $this->assertFalse(self::$imei->runMakeModelCheck(ReceperioService::TEST_INVALID_SERIAL));
        self::$imei->checkSerial(
            static::$phoneA,
            ReceperioService::TEST_INVALID_SERIAL,
            null
        );
        $this->assertNotEquals('serial', self::$imei->getResponseData());
    }
}
