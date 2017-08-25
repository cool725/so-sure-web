<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\LostPhone;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Service\ReceperioService;
use GeoJson\Geometry\Point;

/**
 * @group functional-nonet
 */
class BaseImeiServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $dm;
    protected static $imei;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$imei = self::$container->get('app.imei');
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

        $policy = new PhonePolicy();
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

        $policy = new PhonePolicy();
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

        $policy = new PhonePolicy();
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

        $policy = new PhonePolicy();
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
}
