<?php

namespace AppBundle\Tests\Service;

use AppBundle\Service\BaseImeiService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\LostPhone;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Service\ReceperioService;
use GeoJson\Geometry\Point;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Service\\BaseImeiServiceTest
 */
class BaseImeiServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    protected static $imei;
    protected static $rootDir;
    protected static $filesystem;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        self::$imei = self::$container->get('app.imei');

        self::$rootDir = self::$container->getParameter('kernel.root_dir');
        self::$filesystem = self::$container->get('oneup_flysystem.mount_manager');

    }

    public function tearDown()
    {
    }

    public function testCheckImei()
    {
        $this->assertFalse(self::$imei->checkImei(new Phone(), ReceperioService::TEST_INVALID_IMEI));
    }

    public function testIsLostImeiInvalid()
    {
        $this->assertFalse(self::$imei->isLostImei(0));
    }

    public function testIsLostImeiValid()
    {
        $imeiNumber = rand(1, 999999);
        $lost = new LostPhone();
        $lost->setImei($imeiNumber);
        static::$dm->persist($lost);
        static::$dm->flush();

        //$imeiService = self::$container->get('app.imei');
        //$imeiService->setDm(static::$dm);
        //$this->assertTrue($imeiService->isLostImei($imeiNumber));
        $this->assertTrue(self::$imei->isLostImei($imeiNumber));
    }

    public function testIsImei()
    {
        $this->assertFalse(self::$imei->isImei(0));
        $this->assertFalse(self::$imei->isImei('0'));

        // 356938035643809 should be valid (from internet)
        $this->assertTrue(self::$imei->isImei(356938035643809));
        $this->assertTrue(self::$imei->isImei('356938035643809'));

        // 356938035643809 is valid 356938035643808 changes check digit should invalidate luhn check
        $this->assertFalse(self::$imei->isImei(356938035643808));
        $this->assertFalse(self::$imei->isImei('356938035643808'));
    }

    public function testIsDuplicatePolicyImeiExpired()
    {
        $imeiNumber = rand(1, 999999);

        $this->assertFalse(self::$imei->isDuplicatePolicyImei($imeiNumber));

        $policy = new HelvetiaPhonePolicy();
        $policy->setPremium(new PhonePremium());
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        $policy->setImei($imeiNumber);
        static::$dm->persist($policy);
        static::$dm->flush();

        $this->assertTrue(self::$imei->isDuplicatePolicyImei($imeiNumber));

        $policy->setStatus(PhonePolicy::STATUS_EXPIRED);
        static::$dm->flush();
        $this->assertFalse(self::$imei->isDuplicatePolicyImei($imeiNumber));

        $policy->setStatus(PhonePolicy::STATUS_EXPIRED_CLAIMABLE);
        static::$dm->flush();
        $this->assertFalse(self::$imei->isDuplicatePolicyImei($imeiNumber));
    }

    public function testIsDuplicatePolicyImeiUnrenewed()
    {
        $imeiNumber = rand(1, 999999);

        $this->assertFalse(self::$imei->isDuplicatePolicyImei($imeiNumber));

        $policy = new HelvetiaPhonePolicy();
        $policy->setPremium(new PhonePremium());
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        $policy->setImei($imeiNumber);
        static::$dm->persist($policy);
        static::$dm->flush();

        $this->assertTrue(self::$imei->isDuplicatePolicyImei($imeiNumber));

        $policy->setStatus(PhonePolicy::STATUS_UNRENEWED);
        static::$dm->flush();
        $this->assertFalse(self::$imei->isDuplicatePolicyImei($imeiNumber));
    }

    public function testIsDuplicatePolicyImeiCancelledUserOk()
    {
        $imeiNumber = rand(1, 999999);

        $this->assertFalse(self::$imei->isDuplicatePolicyImei($imeiNumber));

        $policy = new HelvetiaPhonePolicy();
        $policy->setPremium(new PhonePremium());
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        $policy->setImei($imeiNumber);
        static::$dm->persist($policy);
        static::$dm->flush();

        $this->assertTrue(self::$imei->isDuplicatePolicyImei($imeiNumber));

        $policy->setStatus(PhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(PhonePolicy::CANCELLED_COOLOFF);
        static::$dm->flush();
        $this->assertFalse(self::$imei->isDuplicatePolicyImei($imeiNumber));
    }

    public function testIsDuplicatePolicyImeiCancelledPolicyDeclined()
    {
        $imeiNumber = rand(1, 999999);

        $this->assertFalse(self::$imei->isDuplicatePolicyImei($imeiNumber));

        $policy = new HelvetiaPhonePolicy();
        $policy->setPremium(new PhonePremium());
        $policy->setStatus(PhonePolicy::STATUS_ACTIVE);
        $policy->setImei($imeiNumber);
        static::$dm->persist($policy);
        static::$dm->flush();

        $this->assertTrue(self::$imei->isDuplicatePolicyImei($imeiNumber));

        $policy->setStatus(PhonePolicy::STATUS_CANCELLED);
        $policy->setCancelledReason(PhonePolicy::CANCELLED_DISPOSSESSION);
        static::$dm->flush();
        $this->assertTrue(self::$imei->isDuplicatePolicyImei($imeiNumber));
    }

    /**
     * Tests if an IMEI can be disregarded as a duplicate if the policy owning the duplicate is in fact the same policy
     * as the one we are meant to be testing.
     */
    public function testIsDuplicatePolicyImeiSamePolicy()
    {
        $imeiNumber = rand(1, 999999);
        // Test case where the imei is not yet used.
        $this->assertFalse(self::$imei->isDuplicatePolicyImei($imeiNumber));
        // Create policiies with various imeis.
        $a = new HelvetiaPhonePolicy();
        $a->setPremium(new PhonePremium());
        $a->setStatus(PhonePolicy::STATUS_ACTIVE);
        $a->setImei($imeiNumber);
        static::$dm->persist($a);
        $b = new HelvetiaPhonePolicy();
        $b->setPremium(new PhonePremium());
        $b->setStatus(PhonePolicy::STATUS_ACTIVE);
        $b->setImei(rand(1, 999999));
        static::$dm->persist($b);
        static::$dm->flush();
        $c = new HelvetiaPhonePolicy();
        $c->setPremium(new PhonePremium());
        $c->setStatus(PhonePolicy::STATUS_ACTIVE);
        $c->setImei($imeiNumber);
        // Test normal case with just imei which should report a duplicate.
        $this->assertTrue(self::$imei->isDuplicatePolicyImei($imeiNumber));
        // Test case where the policy owning the IMEI is provided so it should not be considered duplicate.
        $this->assertFalse(self::$imei->isDuplicatePolicyImei($imeiNumber, $a));
        // Test case where policy with wrong imei is provided which should find a duplicate since there is one.
        $this->assertTrue(self::$imei->isDuplicatePolicyImei($imeiNumber, $b));
        // Test case where other policy with same imei is used which should find a duplicate.
        $this->assertTrue(self::$imei->isDuplicatePolicyImei($imeiNumber, $c));
    }

    public function testOcrAndroid()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/AndroidImeiDirect.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Samsung');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('353498080807133', $results['imei']);
    }

    public function testOcrIPhoneDirect()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/iPhoneImeiDirect.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('355424073417084', $results['imei']);
        $this->assertNull($results['serialNumber']);
    }

    public function testOcrIPhoneStandard()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/iPhoneSettings.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('355424073417084', $results['imei']);
        $this->assertEquals('C77QMB7SGRY9', $results['serialNumber']);
    }

    public function testOcrIPhoneStandardNoExension()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/iPhoneSettings",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('355424073417084', $results['imei']);
        $this->assertEquals('C77QMB7SGRY9', $results['serialNumber']);
    }

    public function testOcrIPhoneGerman()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/iPhoneSettingsGerman.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('355424073417084', $results['imei']);
        $this->assertEquals('C77QMB7SGRY9', $results['serialNumber']);
    }

    public function testOcrIPhoneX()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/iPhoneXSettings.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('359406087220311', $results['imei']);
        $this->assertEquals('F17VQLU1JCLJ', $results['serialNumber']);
    }

    public function testOcrIPhoneSerialOnly()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/iPhoneSettingsSerialOnly.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertFalse($results['success']);
    }

    public function testOcrError()
    {
        $data = ". vodafone UK as 4:20 pm 1: 24% E]-
< General About
Version 11.0.3 (15A432)
Carrier vodafone UK 29.0
Model MQéJZB/A
Serial Number C77QMB7SGRY9
Wi-Fi Address D0228220178263208
Bluetooth D012Br29278I622D6
IMEI 35 542407 341708 A
ICCID 89441000362956405088
Modem Firmware 106.63
SEID";

        $results = self::$imei->parseOcr($data, 'Apple');
        $this->assertEquals('355424073417084', $results['imei']);
        $this->assertEquals('C77QMB7SGRY9', $results['serialNumber']);
    }

    public function testOcrFailed()
    {
        $time = time();
        $userId = 111;
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/iPhoneSettingsSerialOnly.png",
            self::$rootDir
        );
        // create temporary image
        $testImage = sprintf(
            "%s/%s_OcrFail.png",
            sys_get_temp_dir(),
            $time
        );
        copy($image, $testImage);
        // expecting failure
        $results = self::$imei->ocr($testImage, 'Samsung');
        $this->assertNotNull($results);
        $this->assertFalse($results['success']);

        $url = self::$imei->saveFailedOcr($testImage, $userId, 'png');

        $fs = self::$filesystem->getFilesystem('s3policy_fs');
        $bucket = $fs->getAdapter()->getBucket();
        $pathPrefix = $fs->getAdapter()->getPathPrefix();

        $key = str_replace(sprintf('s3://%s/%s', $bucket, $pathPrefix), '', $url);
        $this->assertTrue($fs->has($key));

        $fs->delete($key);
        unlink($testImage);
    }

    public function testOcr27()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/imei/IMG_0027.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('352987091447138', $results['imei']);
        $this->assertEquals('DNPVPBZQHG7J', $results['serialNumber']);

        $resultsCubeCombined = self::$imei->parseOcr(
            self::$imei->ocrRaw($image, 'png', BaseImeiService::OEM_TESSERACT_CUBE_COMBINED),
            'Apple'
        );
        $this->assertNotNull($resultsCubeCombined);
        $this->assertTrue($resultsCubeCombined['success']);
        $this->assertEquals('352987091447138', $resultsCubeCombined['imei']);
        $this->assertEquals('DNPVPBZQHG7J', $resultsCubeCombined['serialNumber']);
    }

    public function testOcr28()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/imei/IMG_0028.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('352987091447138', $results['imei']);
        $this->assertEquals('DNPVPBZQHG7J', $results['serialNumber']);

        $resultsCubeCombined = self::$imei->parseOcr(
            self::$imei->ocrRaw($image, 'png', BaseImeiService::OEM_TESSERACT_CUBE_COMBINED),
            'Apple'
        );
        $this->assertNotNull($resultsCubeCombined);
        $this->assertTrue($resultsCubeCombined['success']);
        $this->assertEquals('352987091447138', $resultsCubeCombined['imei']);
        $this->assertEquals('DNPVPBZQHG7J', $resultsCubeCombined['serialNumber']);
    }

    public function testOcr29()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/imei/IMG_0029.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('352987091447138', $results['imei']);
        $this->assertEquals('DNPVPBZQHG7J', $results['serialNumber']);

        $resultsCubeCombined = self::$imei->parseOcr(
            self::$imei->ocrRaw($image, 'png', BaseImeiService::OEM_TESSERACT_CUBE_COMBINED),
            'Apple'
        );
        $this->assertNotNull($resultsCubeCombined);
        $this->assertTrue($resultsCubeCombined['success']);
        $this->assertEquals('352987091447138', $resultsCubeCombined['imei']);
        $this->assertEquals('DNPVPBZQHG7J', $resultsCubeCombined['serialNumber']);
    }

    public function testOcr30()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/imei/IMG_0030.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('356648088006230', $results['imei']);
        $this->assertEquals('FRDVD437GRY9', $results['serialNumber']);

        $resultsCubeCombined = self::$imei->parseOcr(
            self::$imei->ocrRaw($image, 'png', BaseImeiService::OEM_TESSERACT_CUBE_COMBINED),
            'Apple'
        );
        $this->assertNotNull($resultsCubeCombined);
        $this->assertTrue($resultsCubeCombined['success']);
        $this->assertEquals('356648088006230', $resultsCubeCombined['imei']);
        $this->assertEquals('FRDVD437GRY9', $resultsCubeCombined['serialNumber']);
    }

    public function testOcr331()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/imei/IMG_0331.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('359210070611613', $results['imei']);
        $this->assertEquals('DNPSCA3EHG7F', $results['serialNumber']);

        $resultsCubeCombined = self::$imei->parseOcr(
            self::$imei->ocrRaw($image, 'png', BaseImeiService::OEM_TESSERACT_CUBE_COMBINED),
            'Apple'
        );
        $this->assertNotNull($resultsCubeCombined);
        $this->assertTrue($resultsCubeCombined['success']);
        $this->assertEquals('359210070611613', $resultsCubeCombined['imei']);
        $this->assertEquals('DNPSCA3EHG7F', $resultsCubeCombined['serialNumber']);
    }

    public function testOcr332()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/imei/IMG_0332.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('359406087220311', $results['imei']);
        $this->assertEquals('F17VQLU1JCLJ', $results['serialNumber']);

        $resultsCubeCombined = self::$imei->parseOcr(
            self::$imei->ocrRaw($image, 'png', BaseImeiService::OEM_TESSERACT_CUBE_COMBINED),
            'Apple'
        );
        $this->assertNotNull($resultsCubeCombined);
        $this->assertTrue($resultsCubeCombined['success']);
        $this->assertEquals('359406087220311', $resultsCubeCombined['imei']);
        $this->assertEquals('F17VQLU1JCLJ', $resultsCubeCombined['serialNumber']);
    }

    public function testOcr6087()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/imei/IMG_6087.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('356648088006230', $results['imei']);
        $this->assertEquals('FRDVD437GRY9', $results['serialNumber']);

        $resultsCubeCombined = self::$imei->parseOcr(
            self::$imei->ocrRaw($image, 'png', BaseImeiService::OEM_TESSERACT_CUBE_COMBINED),
            'Apple'
        );
        $this->assertNotNull($resultsCubeCombined);
        $this->assertTrue($resultsCubeCombined['success']);
        $this->assertEquals('356648088006230', $resultsCubeCombined['imei']);
        $this->assertEquals('FRDVD437GRY9', $resultsCubeCombined['serialNumber']);
    }

    public function testOcrAt()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/imei/at.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        //print_r($results);
        $this->assertEquals('355354080202940', $results['imei']);
        $this->assertEquals('F2NSYYVNHG04', $results['serialNumber']);

        $resultsCubeCombined = self::$imei->parseOcr(
            self::$imei->ocrRaw($image, 'png', BaseImeiService::OEM_TESSERACT_CUBE_COMBINED),
            'Apple'
        );
        //print_r($resultsCubeCombined);
        $resultsCube = self::$imei->parseOcr(
            self::$imei->ocrRaw($image, 'png', BaseImeiService::OEM_CUBE_ONLY),
            'Apple'
        );
        $this->assertEquals($resultsCubeCombined['raw'], $resultsCube['raw']);
        //print_r($resultsCube);
        $resultsTesseract = self::$imei->parseOcr(
            self::$imei->ocrRaw($image, 'png', BaseImeiService::OEM_TESSERACT_ONLY),
            'Apple'
        );
        //print_r($resultsTesseract);
        $this->assertEquals($resultsCubeCombined['raw'], $resultsTesseract['raw']);
        $this->assertEquals($resultsCube['raw'], $resultsTesseract['raw']);
        $this->assertNotNull($resultsCubeCombined);
        $this->assertTrue($resultsCubeCombined['success']);
        $this->assertEquals('355354080202940', $resultsCubeCombined['imei']);
        $this->assertEquals('F2NSYYVNHG04', $resultsCubeCombined['serialNumber']);
    }

    public function testOcrDial()
    {
        $image = sprintf(
            "%s/../src/AppBundle/Tests/Resources/imei/dial.png",
            self::$rootDir
        );

        $results = self::$imei->ocr($image, 'Apple');
        $this->assertNotNull($results);
        $this->assertTrue($results['success']);
        $this->assertEquals('359487087831380', $results['imei']);
        $this->assertEmpty($results['serialNumber']);
    }
}
