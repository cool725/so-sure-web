<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\PhonePolicy;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use GeoJson\Geometry\Point;
use AppBundle\Document\Claim;
use AppBundle\Service\ReceperioService;
use AppBundle\Exception\ReciperoManualProcessException;

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
    /** @var DocumentManager */
    protected static $dm;
    /** @var ReceperioService */
    protected static $imei;
    protected static $phoneRepo;
    protected static $phoneA;
    protected static $phoneB;
    protected static $phoneC;
    protected static $phoneD;
    protected static $phoneE;

    // @codingStandardsIgnoreStart
    const TEST_MSG_APP_IMEI = 'a:1:{s:5:"makes";a:1:{i:0;a:4:{s:4:"make";s:5:"Apple";s:8:"category";i:1;s:7:"serials";a:1:{i:0;s:15:"391674386275365";}s:6:"models";a:17:{i:0;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"JET BLACK";s:7:"storage";s:5:"128GB";}i:1;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"JET BLACK";s:7:"storage";s:5:"256GB";}i:2;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:5:"BLACK";s:7:"storage";s:4:"32GB";}i:3;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:5:"BLACK";s:7:"storage";s:5:"128GB";}i:4;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:5:"BLACK";s:7:"storage";s:4:"16GB";}i:5;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:4:"GOLD";s:7:"storage";s:4:"32GB";}i:6;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:4:"GOLD";s:7:"storage";s:5:"128GB";}i:7;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:4:"GOLD";s:7:"storage";s:5:"256GB";}i:8;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:6:"SILVER";s:7:"storage";s:4:"32GB";}i:9;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:6:"SILVER";s:7:"storage";s:5:"128GB";}i:10;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:6:"SILVER";s:7:"storage";s:5:"256GB";}i:11;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"ROSE GOLD";s:7:"storage";s:4:"32GB";}i:12;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"ROSE GOLD";s:7:"storage";s:5:"128GB";}i:13;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"ROSE GOLD";s:7:"storage";s:5:"256GB";}i:14;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:3:"RED";s:7:"storage";s:5:"128GB";}i:15;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:3:"RED";s:7:"storage";s:5:"256GB";}i:16;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"JET BLACK";s:7:"storage";s:4:"32GB";}}}}}';
    const TEST_MSG_APP_SERIAL = 'a:1:{s:5:"makes";a:1:{i:0;a:4:{s:4:"make";s:5:"APPLE";s:8:"category";i:1;s:7:"serials";a:1:{i:0;s:12:"C39NMUXQG5QY";}s:6:"models";a:1:{i:0;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:4:"GOLD";s:7:"storage";s:5:"128GB";}}}}}';
    const TEST_MSG_ANDROID_SERIAL = 'a:1:{s:5:"makes";a:1:{i:0;a:4:{s:4:"make";s:3:"One";s:8:"category";i:1;s:7:"serials";a:1:{i:0;s:12:"C39NMUXQG5QZ";}s:6:"models";a:1:{i:0;a:4:{s:4:"name";s:1:"3";s:14:"modelreference";s:8:"ONEPLUS3";s:6:"colour";s:4:"GOLD";s:7:"storage";s:4:"64GB";}}}}}';
    const TEST_MSG_SERIAL_MODEL_FAIL = 'a:1:{s:5:"makes";a:1:{i:0;a:4:{s:4:"make";s:5:"Apple";s:8:"category";i:1;s:7:"serials";a:1:{i:0;s:12:"C39NMUXQG5QY";}s:6:"models";a:17:{i:0;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"JET BLACK";s:7:"storage";s:5:"128GB";}i:1;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"JET BLACK";s:7:"storage";s:5:"256GB";}i:2;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:5:"BLACK";s:7:"storage";s:4:"32GB";}i:3;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:5:"BLACK";s:7:"storage";s:5:"128GB";}i:4;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:5:"BLACK";s:7:"storage";s:4:"16GB";}i:5;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:4:"GOLD";s:7:"storage";s:4:"32GB";}i:6;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:4:"GOLD";s:7:"storage";s:5:"128GB";}i:7;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:4:"GOLD";s:7:"storage";s:5:"256GB";}i:8;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:6:"SILVER";s:7:"storage";s:4:"32GB";}i:9;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:6:"SILVER";s:7:"storage";s:5:"128GB";}i:10;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:6:"SILVER";s:7:"storage";s:5:"256GB";}i:11;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"ROSE GOLD";s:7:"storage";s:4:"32GB";}i:12;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"ROSE GOLD";s:7:"storage";s:5:"128GB";}i:13;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"ROSE GOLD";s:7:"storage";s:5:"256GB";}i:14;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:3:"RED";s:7:"storage";s:5:"128GB";}i:15;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:3:"RED";s:7:"storage";s:5:"256GB";}i:16;a:3:{s:4:"name";s:8:"IPHONE 7";s:6:"colour";s:9:"JET BLACK";s:7:"storage";s:4:"32GB";}}}}}';
    // @codingStandardsIgnoreEnd

    //test case constants
    const TEST_IPHONE_SERIAL_VALID = 'C39NMUXQG5QY';
    const TEST_IPHONE_IMEI_VALID = '305796250981516';
    const TEST_ANDROID_SERIAL_VALID = 'C39NMUXQG5QZ';
    const TEST_ANDROID_SERIAL_INVALID = '9876543211';
    const TEST_IPHONE_SERIAL_INVALID = '123456789013';
    const TEST_IPHONE_SERIAL_INVALID2 = '123456789012';

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var ReceperioService $imei */
        $imei = self::$container->get('app.imei');
        self::$imei = $imei;
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        self::$phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = self::$phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneA = self::$phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phoneB = self::$phoneRepo->findOneBy(['devices' => 'A0001', 'memory' => 64]);
        self::$phoneC = self::$phoneRepo->findOneBy(['devices' => 'hero2lte']);
        self::$phoneD = self::$phoneRepo->findOneBy(['devices' => 'OnePlus3']);
        self::$phoneE = self::$phoneRepo->findOneBy(['devices' => 'iPhone 7', 'memory' => 128]);
    }

    public function tearDown()
    {
    }

    public function callValidateSamePhone($phone, $device, $data = array())
    {
        try {
            return self::$imei->validateSamePhone($phone, $device, $data);
        } catch (ReciperoManualProcessException $e) {
            return $e->getCode();
        }
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

    public function testValidateSamePhoneMissingData()
    {
        $this->assertEquals(
            ReciperoManualProcessException::NO_MAKES,
            $this->callValidateSamePhone(static::$phoneA, '123', [])
        );
    }

    public function testValidateSamePhoneNoData()
    {
        $this->assertEquals(
            ReciperoManualProcessException::EMPTY_MAKES,
            $this->callValidateSamePhone(static::$phoneA, '123', ['makes' => []])
        );
    }

    public function testValidateSamePhoneMissingModel()
    {
        $data['makes'][] = ['make' => 'Apple'];
        $this->assertEquals(
            ReciperoManualProcessException::NO_MODELS,
            $this->callValidateSamePhone(static::$phoneA, '123', $data)
        );
    }

    public function testValidateSamePhoneApple()
    {
        $models[] = ['name' => 'iPhone 5', 'storage' => '64GB'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertEquals(
            PhonePolicy::MAKEMODEL_VALID_SERIAL,
            $this->callValidateSamePhone(static::$phoneA, '123', $data)
        );
    }

    public function testValidateSamePhoneAndroid()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB', 'modelreference' => 'A0001'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertEquals(
            PhonePolicy::MAKEMODEL_VALID_IMEI,
            $this->callValidateSamePhone(static::$phoneB, '123', $data)
        );
    }

    public function testValidateSamePhoneAndroidCase()
    {
        $models[] = ['name' => 'GALAXY S7 EDGE', 'storage' => '', 'modelreference' => 'HERO2LTE'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertEquals(
            PhonePolicy::MAKEMODEL_VALID_IMEI,
            $this->callValidateSamePhone(static::$phoneC, '123', $data)
        );
    }

    public function testValidateSamePhoneAndroidMixedCase()
    {
        $models[] = ['name' => '3', 'storage' => '', 'modelreference' => 'ONEPLUS3'];
        $data['makes'][] = ['make' => 'ONEPLUS', 'models' => $models];
        $this->assertEquals(
            PhonePolicy::MAKEMODEL_VALID_IMEI,
            $this->callValidateSamePhone(static::$phoneD, '123', $data)
        );
    }

    public function testValidateSamePhoneAndroidDifferentModel()
    {
        $models[] = ['name' => 'GALAXY S7 EDGE', 'storage' => '', 'modelreference' => 'HERO2LTE'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertEquals(
            ReciperoManualProcessException::DEVICE_NOT_FOUND,
            $this->callValidateSamePhone(static::$phoneB, '123', $data)
        );
    }

    public function testValidateSamePhoneAndroidMissingModelRef()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertEquals(
            ReciperoManualProcessException::NO_MODEL_REFERENCE,
            $this->callValidateSamePhone(static::$phoneB, '123', $data)
        );
    }

    public function testValidateSamePhoneAndroidMultipleMemory()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB', 'modelreference' => 'A0001'];
        $models[] = ['name' => 'A', 'storage' => '16GB', 'modelreference' => 'A0001'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertEquals(
            PhonePolicy::MAKEMODEL_VALID_IMEI,
            $this->callValidateSamePhone(static::$phoneB, $this->generateRandomImei(), $data)
        );
    }

    public function testValidateSamePhoneAndroidDifferentModelMem()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB', 'modelreference' => 'A0001'];
        $models[] = ['name' => 'B', 'storage' => '64GB', 'modelreference' => 'A0001'];
        $data['makes'][] = ['make' => 'A', 'models' => $models];
        $this->assertEquals(
            ReciperoManualProcessException::MODEL_MISMATCH,
            $this->callValidateSamePhone(static::$phoneB, '123', $data)
        );
    }

    public function testValidateSamePhoneAppleMultipleMemory()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB'];
        $models[] = ['name' => 'A', 'storage' => '16GB'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertEquals(
            ReciperoManualProcessException::MODEL_MISMATCH,
            $this->callValidateSamePhone(static::$phoneA, '123', $data)
        );
    }

    public function testValidateSamePhoneAppleDifferentModel()
    {
        $models[] = ['name' => 'A', 'storage' => '64GB'];
        $models[] = ['name' => 'A', 'storage' => '16GB'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertEquals(
            ReciperoManualProcessException::MODEL_MISMATCH,
            $this->callValidateSamePhone(static::$phoneA, '123', $data)
        );
    }

    public function testValidateSamePhoneAppleDifferentColours()
    {
        $models[] = ['name' => 'iPhone 5', 'storage' => '64GB', 'color' => 'white'];
        $models[] = ['name' => 'iPhone 5', 'storage' => '64GB', 'color' => 'black'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertEquals(
            PhonePolicy::MAKEMODEL_VALID_SERIAL,
            $this->callValidateSamePhone(static::$phoneA, '123', $data)
        );
    }

    public function testValidateSamePhoneAppleIncorrectModel()
    {
        $models[] = ['name' => 'foo', 'storage' => '16GB', 'color' => 'white'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertEquals(
            ReciperoManualProcessException::MODEL_MISMATCH,
            $this->callValidateSamePhone(static::$phoneA, '123', $data)
        );
    }

    public function testValidateSamePhoneAppleIncorrectMemory()
    {
        $models[] = ['name' => 'iPhone 5', 'storage' => '16GB', 'color' => 'white'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertEquals(
            ReciperoManualProcessException::MEMORY_MISMATCH,
            $this->callValidateSamePhone(static::$phoneA, '123', $data)
        );
    }

    public function testValidateSamePhoneAppleImeiSkipsMemory()
    {
        $models[] = ['name' => 'iPhone 5', 'storage' => '16GB', 'color' => 'white'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertEquals(
            PhonePolicy::MAKEMODEL_VALID_IMEI,
            $this->callValidateSamePhone(static::$phoneA, $this->generateRandomImei(), $data)
        );
    }

    public function testValidateSamePhoneAppleMissingStorage()
    {
        $models[] = ['name' => 'iPhone 5', 'color' => 'white'];
        $data['makes'][] = ['make' => 'Apple', 'models' => $models];
        $this->assertEquals(
            ReciperoManualProcessException::NO_MEMORY,
            $this->callValidateSamePhone(static::$phoneA, '123', $data)
        );
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

    public function testAppleManualProcessSerialRetry()
    {
        $this->assertFalse(self::$imei->runMakeModelCheck(ReceperioService::TEST_INVALID_SERIAL));
        $this->assertTrue(self::$imei->checkSerial(
            static::$phoneA,
            ReceperioService::TEST_MANUAL_PROCESS_SERIAL,
            $this->generateRandomImei()
        ));
        $this->assertEquals('serial', self::$imei->getResponseData());
    }

    public function testAppleManualProcessModelMisMatch()
    {
        $this->runCheckSerial(
            static::$phoneA,
            self::TEST_IPHONE_SERIAL_VALID,
            $this->generateRandomImei()
        );
        $this->assertContains(PhonePolicy::MAKEMODEL_MODEL_MISMATCH, self::$imei->getMakeModelValidatedStatus());
    }

    public function testAppleInvalidSerialNoRetry()
    {
        $this->assertFalse(
            self::$imei->runMakeModelCheck(ReceperioService::TEST_INVALID_SERIAL),
            'MakeModel'
        );

        static::$imei->setResponseData(null, false);

        $this->assertFalse(
            self::$imei->checkSerial(
                static::$phoneA,
                ReceperioService::TEST_INVALID_SERIAL,
                null
            ),
            'checkSerial'
        );
        $this->assertNotEquals('serial', self::$imei->getResponseData());
    }

    public function testAppleValidSerial()
    {
        $this->assertTrue($this->runCheckSerial(
            static::$phoneE,
            self::TEST_IPHONE_SERIAL_VALID,
            null
        ));
        $this->assertContains(PhonePolicy::MAKEMODEL_VALID_SERIAL, self::$imei->getMakeModelValidatedStatus());
    }

    public function testAppleInvalidModel()
    {
        $this->assertFalse($this->runCheckSerial(
            static::$phoneA,
            self::TEST_IPHONE_SERIAL_INVALID,
            self::TEST_IPHONE_IMEI_VALID
        ));
        $this->assertContains(PhonePolicy::MAKEMODEL_MODEL_MISMATCH, self::$imei->getMakeModelValidatedStatus());
    }

    public function testAppleValidIMEI()
    {
        $this->assertTrue($this->runCheckSerial(
            static::$phoneE,
            self::TEST_IPHONE_SERIAL_INVALID2,
            self::TEST_IPHONE_IMEI_VALID
        ));
        $this->assertContains(PhonePolicy::MAKEMODEL_VALID_IMEI, self::$imei->getMakeModelValidatedStatus());
    }

    public function testAndroidSerialInvalid()
    {
        // message from recipero will be valid but serials check is ignored
        $this->assertTrue($this->runCheckSerial(
            static::$phoneD,
            self::TEST_ANDROID_SERIAL_INVALID,
            null
        ));
        $this->assertContains(PhonePolicy::MAKEMODEL_VALID_IMEI, self::$imei->getMakeModelValidatedStatus());
    }

    public function testAndroidSerialValid()
    {
        $this->assertTrue($this->runCheckSerial(
            static::$phoneD,
            self::TEST_ANDROID_SERIAL_VALID,
            null
        ));
        $this->assertContains(PhonePolicy::MAKEMODEL_VALID_IMEI, self::$imei->getMakeModelValidatedStatus());
    }

    public function runCheckSerial(
        Phone $phone,
        $serialNumber,
        $imei = null
    ) {
        switch ($serialNumber) {
            case self::TEST_IPHONE_IMEI_VALID:
                self::$imei->setResponseData(unserialize(self::TEST_MSG_APP_IMEI), true);
                break;
            case self::TEST_IPHONE_SERIAL_VALID:
                self::$imei->setResponseData(unserialize(self::TEST_MSG_APP_SERIAL), true);
                break;
            case self::TEST_IPHONE_SERIAL_INVALID:
                self::$imei->setResponseData(unserialize(self::TEST_MSG_APP_SERIAL), true);
                break;
            case self::TEST_IPHONE_SERIAL_INVALID2:
                self::$imei->setResponseData(unserialize(self::TEST_MSG_APP_IMEI), true);
                break;
            case self::TEST_ANDROID_SERIAL_INVALID:
                self::$imei->setResponseData(unserialize(self::TEST_MSG_ANDROID_SERIAL), true);
                break;
            case self::TEST_ANDROID_SERIAL_VALID:
                self::$imei->setResponseData(unserialize(self::TEST_MSG_ANDROID_SERIAL), true);
                break;
        }
        $result = self::$imei->checkSerial(
            $phone,
            $serialNumber,
            $imei
        );

        self::$imei->setResponseData(null, true);

        return $result;
    }
}
